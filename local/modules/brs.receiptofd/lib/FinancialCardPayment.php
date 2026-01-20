<?php


namespace Brs\ReceiptOfd;


use Bitrix\Crm\DealTable;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Brs\Entities\Deal;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Models\RefundCardTable;
use Brs\FinancialCard\Repository\CorrectionCard;
use Brs\FinancialCard\Repository\FinancialCard;
use Brs\FinancialCard\Repository\PointPayment;
use Brs\IncomingPaymentEcomm\AverageRate;
use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;
use Brs\IncomingPaymentEcomm\Repository\PaymentTransaction;
use Brs\Main\Crm\Deal\Course;
use Brs\Models\OfferTable;
use Brs\Point\Agent\AccountingEntry;
use Brs\Point\Service\AccountingEntryService;
use Brs\ReceiptOfd\Models\ReceiptTable;
use Brs\ReceiptOfd\ReceiptStrategy\ReturnFinalPayment;
use Brs\Services\DealDate;
use Brs\ReceiptOfd\Receipt;
use Brs\FinancialCard\Credit;
use CAllCrmDeal;
use CCrmContact;
use CCrmDeal;
use CCrmOwnerType;
use CIBlockElement;
use Exception;
use Monolog\Registry;

class FinancialCardPayment
{
	private const RECEIPT_STRATEGY = [
		'SR_SUPPLIER_AGENT' => 'getReceiptFieldsSrSupplierAgent',
		'LR_SUPPLIER_AGENT' => 'getReceiptFieldsLrSupplierAgent',
		'BUYER_AGENT' => 'getReceiptFieldsBuyerAgent',
		'PROVISION_SERVICES' => 'getReceiptFieldsServices',
		'RS_TLS_SERVICE_FEE' => 'getReceiptFieldsRsTlsServiceFee'
	];

	private int $dealId;
	private $paymentType;
	private int $companyId;
	private string $companyName;
	private string $strategyTypeByDate;
	/** @var array|false */
	private $deal;
	/** @var array|false */
	private $finCard;
	private array $price;
	/** @var Date Дата печати чека полного расчёта */
	public Date $dealBeginTime;

	private bool $isCorrection = false;
	private float $currencyRate;

	private bool $isPreReceipt = false;

	public function __construct(int $dealId)
	{
		try {
			$this->dealId = $dealId;

			Loader::includeModule('crm');
			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.receiptofd');
			Loader::includeModule('brs.incomingpaymentecomm');

			$paymentTypeInfo = FinancialCard::getDealPaymentType($this->dealId);
			$this->paymentType = $paymentTypeInfo['PAYMENT_TYPE'];
			$this->payDate = $paymentTypeInfo['PAY_DATE'];

			$this->finCard = $this->getFinCard();
			if ($this->finCard) {
				$this->isCorrection = boolval($this->finCard['IS_CORRECTION_AFTER_DEAL']);
				$this->price = $this->getPrice($this->finCard['ID']);
				if ($this->price['CURRENCY']) {
					$this->currencyRate = Course::getMiddlePrice($dealId);
				}
				$this->companyId = $this->getIdSelectedCompany();
				$this->companyName = $this->getCompanyName();
			}

			$this->deal = DealTable::getlist(['filter'=>['ID' => $this->dealId], 'select'=>['*', 'UF_*'], 'limit'=>1])->fetch();

			$dealDate = new DealDate();
			$this->dealBeginTime = $dealDate->getDatePrintFullReceipt($this->dealId);
		} catch (\Throwable $throwable) {
			$throwableMessage = $throwable->getMessage();

			$logger = Registry::getInstance('receipt');
			$logger->error(
				$throwableMessage,
				[
					'DEAL_ID' => $this->dealId,
					'ERROR_TEXT' => $throwableMessage . PHP_EOL . $throwable->getTraceAsString()
				]
			);;

			throw new Exception($throwableMessage);
		}
	}

	public function run(): bool
	{
		try {

			if ($this->paymentType === 'ADVANCE') {
				$this->makeAdvance();
			}

			return true;

		} catch (\Throwable $throwable) {
			$throwableMessage = $throwable->getMessage();

			$logger = Registry::getInstance('receipt');
			$logger->error(
				$throwableMessage,
				[
					'DEAL_ID' => $this->dealId,
					'ERROR_TEXT' => $throwableMessage . PHP_EOL . $throwable->getTraceAsString()
				]
			);

			return false;
		}
	}

	/**
	 * Предварительный чек
	 *
	 * @return false|void
	 */
	public function makePreReceipt() {
		try {
			$this->isPreReceipt = true;

			$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);

			$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека

			if ($this->paymentType === 'ADVANCE') {

				$this->strategyTypeByDate = $this->getStrategyTypeByDate($finCardType);

				$advanceSum = $this->getAdvanceSum();

				if(round($advanceSum) != round($this->price['RESULT'])) {
					if($advanceSum == 0){
						$this->strategyTypeByDate = 'CREDIT_TRANSFER';
					} else {
						$this->strategyTypeByDate = 'CREDIT';
					}
				}

				$receiptStrategy = $this->$strategyName($this->price, $this->finCard);

				if(isset($advanceSum) && $this->strategyTypeByDate !== 'PREPAYMENT'){
					if($advanceSum > 0){
						$receiptStrategy->setPreCreditAdvance($advanceSum);
					}
				}
			} else {

				$advanceSum = $this->getAdvanceSum();

				if(round($advanceSum) != round($this->price['RESULT'])) {
					$this->strategyTypeByDate = 'CREDIT';
				} else {
					$this->strategyTypeByDate = 'CREDIT_TRANSFER'; // тип чека "Полный расчёт по кредиту"
				}

				$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека

				if($advanceSum > 0){
					$receiptStrategy->setPreCreditAdvance($advanceSum);
				}

			}

			$manager = new Receipt\Manager;

			$manager->setDealId($this->dealId);
			$manager->setPaymentId(0);
			$manager->setStrategy($receiptStrategy);

			return $manager->createPreReceipt();

		} catch (\Throwable $throwable) {
			$throwableMessage = $throwable->getMessage();

			$logger = Registry::getInstance('receipt');
			$logger->error(
				$throwableMessage,
				[
					'DEAL_ID' => $this->dealId,
					'ERROR_TEXT' => $throwableMessage . PHP_EOL . $throwable->getTraceAsString()
				]
			);

			return false;
		}
	}

	/** Схема оплаты клиентом Авансовая */
	public function makeAdvance()
	{
		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);
		$this->strategyTypeByDate = $this->getStrategyTypeByDate($finCardType);

		// TODO: Нужно вынести обработку балами в отдельный метод из makeAdvance в метод типа makePayPoint
		$pointService = new PointPayment();
		$isPaymentByPoint = $pointService->hasPointsPayment($this->dealId);

		if ($isPaymentByPoint) {
			$this->makePoint();
		} else {
			if ($this->strategyTypeByDate === 'PREPAYMENT') {
				$this->addAgentFullPayment();
			}

			$strategyName = self::RECEIPT_STRATEGY[$finCardType];

			$receiptStrategy = $this->$strategyName($this->price, $this->finCard);

			$manager = new Receipt\Manager;

			$manager->setDealId($this->dealId);
			$manager->setPaymentId(0);
			$manager->setStrategy($receiptStrategy);

			$manager->create();
		}
	}

	public function makePoint()
	{
		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);
		$this->strategyTypeByDate = $this->getStrategyTypeByDate($finCardType);

		if ($this->strategyTypeByDate === 'PREPAYMENT') {
			$this->addAgentFullPayment();
		} else {
			$accountingEntryService = new AccountingEntryService();
			$accountingEntryService->createRealizationEntrance($this->finCard['DEAL_ID']);
		}

		$strategyName = self::RECEIPT_STRATEGY[$finCardType];

		$receiptStrategy = $this->$strategyName($this->price, $this->finCard);
		$receiptStrategy->setPaymentTransferCredit();

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create();
	}

	public function makeRefundPoint()
	{
		Loader::includeModule('brs.financialcard');

		$this->strategyTypeByDate = 'CREDIT_REFUND_TRANSFER';

		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']); // получаем схему работы финансовой карты
		$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека

		$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека

		$receiptStrategy->setIsFirstCheckCredit(false); // первый ли чек по кредиту
		$receiptStrategy->setPreCreditAdvance(0); // оплаченная сумма по платежам

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе
	}

	private function makeCredit()
	{
		if (FinancialCard::hasPaidFull($this->dealId)) {
			$this->makeAdvance();
		} else {
			$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);
			$isStarted = $this->dealBeginTime->getTimestamp() <= (new DateTime())->getTimestamp();
			if ($this->isMomentaryProcess($finCardType) || $isStarted) {
				$this->makeCreditTransfer();
			} else {
				\CAgent::AddAgent(
					'Brs\ReceiptOfd\Agent\ReceiptOfdAgent::checkFullPaid(' . $this->dealId . ');',
					'brs.receiptofd',
					'Y',
					10,
					'',
					'Y',
					$this->dealBeginTime
				);
			}
		}
	}

	/**
	 * Создаёт чек "Передача в кредит"
	 * 
	 * @return void
	 */
	public function makeCreditTransfer(): void {

		$advanceSum = $this->getAdvanceSum();

		$this->strategyTypeByDate = 'CREDIT_TRANSFER'; // тип чека "Передача в кредит"

		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']); // получаем схему работы финансовой карты
		$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека

		$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека

		if($advanceSum > 0){
			$receiptStrategy->setPreCreditAdvance($advanceSum);
		}

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	/**
	 * Создаёт чек "Оплата кредита" или "Частичный расчет и кредит"
	 * 
	 * @param bool $isFirstCheck первый чек по кредиту?
	 * @param int $paymentId идентификатор платежа к которому привязываем чек
	 * @return void
	 * @throws Exception
	 */
	public function makeCreditPayment(bool $isFirstCheck = false, int $paymentId = 0): void {

		Loader::includeModule('brs.financialcard');

		$credit = Credit::infoByDeal($this->dealId); // получаем информацию по текущему активному кредиту

		if(!$credit){
			throw new Exception('Не удалось найти активный кредит по текущей сделке');
		}

		if($credit['AMOUNT_REMAINING'] > 0){ // если есть активный и не до конца оплаченный кредит в сделке (т.е. сумма остатка платежей по кредиту больше 0)
			$this->strategyTypeByDate = 'CREDIT'; // тип чека "Частичный расчёт по кредиту"
		} else {
			$this->strategyTypeByDate = 'CREDIT_FULL'; // тип чека "Полный расчёт по кредиту"
		}

		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']); // получаем схему работы финансовой карты
		$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека
		
		$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека
		
		$receiptStrategy->setIsFirstCheckCredit($isFirstCheck); // первый ли чек по кредиту
		$receiptStrategy->setPartialCredit($credit['AMOUNT_LAST_PAYMENT']); // сумма последнего платежа

		if($credit['AMOUNT_PAID'] > 0) {
			$receiptStrategy->setPreCreditAdvance($credit['AMOUNT_PAID']); // оплаченная сумма по платежам
		}

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId($paymentId);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	/**
	 * Создаёт чек "Возврат передачи в кредит"
	 * 
	 * @return void
	 */
	public function makeCreditRefundTransfer(): void {

		$advanceSum = $this->getAdvanceSum();

		$this->strategyTypeByDate = 'CREDIT_REFUND_TRANSFER'; // тип чека "Возврат передачи в кредит"

		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']); // получаем схему работы финансовой карты
		$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека

		$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека
		
		if($advanceSum > 0){
			$receiptStrategy->setPreCreditAdvance($advanceSum);
		}

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	/**
	 * Создаёт чек "Полный возврат по кредиту" или "Частичный возврат по кредиту"
	 * 
	 * @param bool $isFirstCheck первый чек по кредиту?
	 * @param int $paymentId идентификатор платежа к которому привязываем чек
	 * @return void
	 * @throws Exception
	 */
	public function makeCreditRefundPayment(bool $isFirstCheck = false, int $paymentId = 0): void {

		Loader::includeModule('brs.financialcard');

		$credit = Credit::infoByDeal($this->dealId); // получаем информацию по текущему активному кредиту

		if(!$credit){
			throw new Exception('Не удалось найти активный кредит по текущей сделке');
		}

		$financialOperationLast = Credit\FinancialOperation::getLast($credit['ID']); // получаем последнюю финансовую операцию

		$isFullRefund = false;
		$isFullPayment = Credit\FinancialOperation::isFullPayment($credit['ID']); // есть ли финансовая операция полной оплаты кредита

		if(is_array($financialOperationLast)){ // если найдена последняя финансовая операция
			$isFullRefund = $financialOperationLast['TYPE_CODE'] == 'REFUND_FULL_PAID'; // если последняя финансовая операция в кредите соответствует значению "Полный возврат" в поле "Тип"
		}

		if($isFullPayment){
			$this->strategyTypeByDate = 'CREDIT_REFUND_FULL'; // тип чека "Полный возврат по кредиту"
		} else if($isFullRefund){
			$this->strategyTypeByDate = 'CREDIT_REFUND_FULL'; // тип чека "Полный возврат по кредиту"
		} else {
			$this->strategyTypeByDate = 'CREDIT_REFUND'; // тип чека "Частичный возврат по кредиту"
		}

		if($credit['AMOUNT_LAST_PAYMENT'] < 0){ // если сумма последнего платежа отрицательная, то делаем её положительной
			$credit['AMOUNT_LAST_PAYMENT'] *= -1; 
		}
		
		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']); // получаем схему работы финансовой карты
		$strategyName = self::RECEIPT_STRATEGY[$finCardType]; // на основе схемы работы финансовой карты получаем метод формирования параметров чека
		
		$receiptStrategy = $this->$strategyName($this->price, $this->finCard); // в зависимости от схемы финансовой карты заполняем параметры чека
		
		$receiptStrategy->setIsFirstCheckCredit($isFirstCheck); // первый ли чек по кредиту
		$receiptStrategy->setPartialCredit($credit['AMOUNT_LAST_PAYMENT']); // сумма последнего платежа

		$receiptStrategy->setPreCreditAdvance($credit['AMOUNT_PAID']); // оплаченная сумма по платежам
		
		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId($paymentId);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	/**
	 * Возвращает аванс, сумма рассчитывается автоматически - либо можно указать сумму вручную,
	 * если были штрафы и сумма возвращается не полная
	 * @param float|null $returnPrice
	 */
	public function returnDealAdvance(?float $returnPrice = null, bool $isRealReturn = false)
	{
		$dbPayments = PaymentTransactionTable::getList(
			[
				'filter' => [
					'=DEAL_ID' => $this->dealId,
					'=STATUS' => 'SUCCESS',
					'=PAYMENT_TYPE' => 'INCOMING'
				]
			]
		);

		$user = CCrmContact::GetByID(CCrmDeal::GetByID($this->dealId)['CONTACT_ID']);

		if ($returnPrice) {
			$totalSumPayment = $returnPrice;
		} else {
			$totalSumPayment = 0;

			while ($payment = $dbPayments->fetch()) {
				$totalSumPayment += $payment['AMOUNT'];
			}
		}

		$invoiceId = implode(
			'_',
			[
				'RETURN_ADVANCE_DEAL',
				$this->dealId,
				0
			]
		);

		$receiptOptions = [
			'PRICE' => $totalSumPayment,
			'USER_NAME' => implode(' ', [$user['LAST_NAME'], $user['NAME'], $user['SECOND_NAME']]),
			'INVOICE_ID' => $invoiceId,
			'DEAL_ID' => $this->dealId
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();
		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'RETURN',
			'ADVANCE'
		);

		if ($isRealReturn) {
			$receiptStrategy->setRealReturn();
		}

		$receiptStrategy->setEmail(\COption::GetOptionString('brs.receiptofd', 'RECEIPT_SERVICE_EMAIL'));

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	/**
	 * Печатает чеки возврата. Вызывается из карточки возврата сделки.
	 * Вариант чека зависит от того какой последний чек в сделке был напечатан.
	 */
	public function returnDealRefund()
	{
		$dbRefundCard = RefundCardTable::getList(
			[
				'filter' => ['=DEAL_ID' => $this->dealId]
			]
		);

		$refundCard = $dbRefundCard->fetch();

		$dbReceipts = ReceiptTable::getList(
			[
				'order' => ['ID' => 'DESC'],
				'filter' => ['DEAL_ID' => $this->dealId],
				'limit' => 1
			]
		);

		$receipt = $dbReceipts->fetch();

		$isAdvance = $receipt['PAYMENT_TYPE'] === 'ADVANCE';

		if ($isAdvance) {
			$returnPrice = $refundCard['RETURN_CASH'];
			$this->returnDealAdvance($returnPrice, true);
		} else {
			$refundCard['RETURN_CASH'] = floatval($refundCard['RETURN_CASH']);

			$averageRate = AverageRate::get($this->dealId);
			$isCurrencyFinCard = $averageRate && $averageRate['AVERAGE_CURRENCY_ID'] !== 'RUB';

			if ($isCurrencyFinCard) {
				$returnPriceCollectingRsTls = floatval($refundCard['RS_TLS_FEE_CURRENCY']) * $averageRate['AVERAGE_RATE'] * $averageRate['AVERAGE_RATE_CNT'];
				$returnPriceCollectingSupplier = floatval($refundCard['SUPPLIER_RETURN_CURRENCY']) * $averageRate['AVERAGE_RATE'] * $averageRate['AVERAGE_RATE_CNT'];
			} else {
				$returnPriceCollectingRsTls = floatval($refundCard['RS_TLS_FEE']);
				$returnPriceCollectingSupplier = floatval($refundCard['SUPPLIER_RETURN']);
			}

			$returnPriceProduct = $refundCard['RETURN_CASH'] - $returnPriceCollectingRsTls - $returnPriceCollectingSupplier;
			$returnPriceProduct = round($returnPriceProduct, 2);

			if ($refundCard['DIRECTION_TYPE'] === RefundCardTable::DIRECTION_TYPE_CARD) {
				$isRealReturn = true;
			} else {
				$isRealReturn = false;
			}

			$this->returnFinalPayment(
				$receipt['PAYMENT_TYPE'],
				$returnPriceProduct,
				$returnPriceCollectingRsTls,
				$returnPriceCollectingSupplier,
				$isRealReturn
			);
		}
	}

	public function makePaymentCorrection(): bool
	{
		try {

			Loader::includeModule('brs.incomingpaymentecomm');

			$this->isCorrection = true;

			$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);
			$this->strategyTypeByDate = $this->getStrategyTypeByDate($finCardType);

			if($this->strategyTypeByDate === 'PREPAYMENT'){

				\CAgent::AddAgent(
					$this->getAgentFunction(),
					'brs.receiptofd',
					'Y',
					10,
					'',
					'Y',
					$this->dealBeginTime
				);

				$isExistPaymentSuccessTransaction = PaymentTransactionTable::getList([
					'filter' => [
						'DEAL_ID' => $this->dealId,
						'STATUS' => PaymentTransactionTable::PAYMENT_STATUS_SUCCESS,
						'CREATOR_TYPE' => 'CREATOR_TYPE_CORRECTION'
					]
				])->getSelectedRowsCount() > 0;

				if(!$isExistPaymentSuccessTransaction){ // если нет списаний в карте коррекции, то чек не создаём
					return true;
				}

				$price = $this->getDifferencePriceCardCorrection();

			} else {
				$price = $this->price;
			}

			$strategyName = self::RECEIPT_STRATEGY[$finCardType];

			$receiptStrategy = $this->$strategyName($price, $this->finCard);

			$manager = new Receipt\Manager;

			$manager->setDealId($this->dealId);
			$manager->setPaymentId(0);
			$manager->setStrategy($receiptStrategy);

			$manager->create(); // создаём чек в системе

			return true;
		} catch (\Throwable $throwable) {
			$throwableMessage = $throwable->getMessage();

			$logger = Registry::getInstance('receipt');
			$logger->error(
				$throwableMessage,
				[
					'DEAL_ID' => $this->dealId,
					'ERROR_TEXT' => $throwableMessage . PHP_EOL . $throwable->getTraceAsString()
				]
			);

			return false;
		}
	}

	private function getAdvanceSum(): float
	{
		$dbPayments = PaymentTransactionTable::getList(
			[
				'filter' => [
					'=DEAL_ID' => $this->dealId,
					'=STATUS' => 'SUCCESS',
					'=PAYMENT_TYPE' => 'INCOMING'
				],
				'select' => ['AMOUNT']
			]
		);

		$payments = $dbPayments->fetchAll();

		if ($payments) {
			return array_sum(array_column($payments, 'AMOUNT'));
		} else {
			return 0;
		}
	}

	private function getPaymentsInfo(): array
	{
		$dbPayments = PaymentTransactionTable::getList(
			[
				'filter' => [
					'=DEAL_ID' => $this->dealId,
					'=STATUS' => 'SUCCESS',
					'=PAYMENT_TYPE' => 'INCOMING'
				],
				'select' => ['AMOUNT'],
				'order' => ['ID' => 'DESC']
			]
		);

		$payments = $dbPayments->fetchAll();

		if (count($payments) > 1) {
			$partial = array_shift($payments)['AMOUNT'];
			$advance = array_sum(array_column($payments, 'AMOUNT'));
			return [
				'ADVANCE' => $advance,
				'PARTIAL' => $partial
			];
		} else {
			return [
				'ADVANCE' => 0,
				'PARTIAL' => $payments['AMOUNT']
			];
		}
	}

	private function getIdSelectedCompany(): int
	{
		$companyId = OfferTable::getList(
			[
				'filter' => ['UF_DEAL_ID' => $this->dealId, 'UF_STATUS' => OfferTable::getApproachStatuses()],
				'limit' => 1,
				'select' => ['UF_COMPANY_ID']
			]
		)->fetch()['UF_COMPANY_ID'];

		if (!$companyId) {
			throw new Exception('the deal does not have a confirmed supplier (company)');
		}

		return $companyId;
	}

	private function getCompanyName(): string
	{

		$companyRequisiteFullName = FinancialCard::getCompanyRequisiteFullName($this->dealId);

		if (!$companyRequisiteFullName) {
			throw new Exception('У партнёра в реквизитах не заполнено полное наименование компании');
		}

		return $companyRequisiteFullName;
	}

	private function getRequisiteInn(): string
	{
		$result = RequisiteTable::getList(
			[
				'filter' => [
					'ENTITY_TYPE_ID' => CCrmOwnerType::Company,
					'ENTITY_ID' => $this->companyId,
					'!=PRESET_ID' => REQUISITE_BANK_PRESET_ID,
					'ADDRESS_ONLY' => 'N'
				],
				'limit' => 1,
				'select' => ['RQ_INN', 'PRESET_ID']
			]
		)->fetch();

		if (!$result) {
			throw new \Exception('Company not INN');
		}

		$residentRf = [REQUISITE_ORGANIZATION_PRESET_ID, REQUISITE_INDIVIDUAL_ENTREPRENEUR_PRESET_ID];

		if (in_array($result['PRESET_ID'], $residentRf)) {
			return $result['RQ_INN'];
		} elseif ($result['PRESET_ID'] == REQUISITE_NO_RF_PRESET_ID) {
			// Инн иностранных компаний
			return '000000000000';
		} else {
			throw new \Exception('Not valid company Requisite');
		}
	}

	private function getNomenclature()
	{
		$dbDeal = CAllCrmDeal::GetListEx(
			[],
			['=ID' => $this->dealId],
			false,
			false,
			['UF_BRS_CRM_DEAL_NOMENCLATURE_IN_CHEQUE']
		);
		$deal = $dbDeal->Fetch();
		$nomenclatureId = current($deal['UF_BRS_CRM_DEAL_NOMENCLATURE_IN_CHEQUE']);
		$dbNomenclature = CIBlockElement::GetByID($nomenclatureId)->GetNextElement();

		return $dbNomenclature->GetProperties()['CHEQUE_TITLE']['VALUE'];
	}

	public function getStrategyTypeByDate(string $finCardType): string
	{
		if ($this->isMomentaryProcess($finCardType)) {
			$strategyTypeByDate = 'FULL_PAYMENT';
		} elseif ($this->dealBeginTime->getTimestamp() <= (new DateTime())->getTimestamp()) {
			$strategyTypeByDate = 'FULL_PAYMENT';
		} else {
			$strategyTypeByDate = 'PREPAYMENT';
		}

		return $strategyTypeByDate;
	}

	public function addAgentFullPayment(): void
	{
		$agentDate = $this->dealBeginTime;

		// Если агент выставляется на утро 00:00:00 то ставим его на 10:05:00
		if ($agentDate->format('His') === '000000') {
			$agentDate->add('+10 hour +5 minutes');
		}

		\CAgent::AddAgent(
			$this->getAgentFunction(),
			'brs.receiptofd',
			'Y',
			10,
			'',
			'Y',
			$agentDate
		);
	}

	private function getReceiptFieldsSrSupplierAgent(array $prices, array $finCard): ReceiptStrategy\AbstractReceipt
	{
		$inn = $this->getRequisiteInn();

		$invoiceId = implode(
			'_',
			[
				$this->strategyTypeByDate,
				'DEAL',
				$this->dealId,
				(new DateTime())->format('H_i_s_d_m_Y')
			]
		);

		if ($prices['CURRENCY']) {
			$priceSupplier = $prices['SUPPLIER_CURRENCY'] * $this->currencyRate;
			$service = $prices['SERVICE_CURRENCY'] * $this->currencyRate;
			$supplierPenalty = $prices['SUPPLIER_PENALTY_CURRENCY'] * $this->currencyRate;
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT_CURRENCY'] * $this->currencyRate;
			$rstlsPenalty = $prices['RSTLS_PENALTY_CURRENCY'] * $this->currencyRate;
		} else {
			$priceSupplier = $prices['SUPPLIER'];
			$service = $prices['SERVICE'];
			$supplierPenalty = $prices['SUPPLIER_PENALTY'];
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT'];
			$rstlsPenalty = $prices['RSTLS_PENALTY'];
		}

		$receiptOptions = [
			'INVOICE_ID' => $invoiceId,
			'PRICE' => $this->getFullPriceForReceipt(),
			'DEAL_ID' => $this->dealId,
			'SERVICE_RS_TLS' => $service,
			'SUPPLIER' => $priceSupplier,
			'SUPPLIER_VAT' => $finCard['SUPPLIER_VAT'],
			'COMPANY_INN' => $inn,
			'COMPANY_NAME' => $this->companyName,
			'PRODUCT_NAME' => $this->getNomenclature(),
			'SUPPLIER_PENALTY' => $supplierPenalty,
			'SUPPLIER_REPLACEMENT' => $supplierReplacement,
			'RSTLS_PENALTY' => $rstlsPenalty,
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();

		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'INCOME',
			'AGENT_SUPPLIER_SR',
			$this->strategyTypeByDate
		);

		return $receiptStrategy;
	}

	private function getReceiptFieldsLrSupplierAgent(array $prices, array $finCard): ReceiptStrategy\AbstractReceipt
	{
		$invoiceId = implode(
			'_',
			[
				$this->strategyTypeByDate,
				'DEAL',
				$this->dealId,
				(new DateTime())->format('H_i_s_d_m_Y')
			]
		);

		if ($prices['CURRENCY']) {
			$supplierPenalty = $prices['SUPPLIER_PENALTY_CURRENCY'] * $this->currencyRate;
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT_CURRENCY'] * $this->currencyRate;
			$rstlsPenalty = $prices['RSTLS_PENALTY_CURRENCY'] * $this->currencyRate;
		} else {
			$supplierPenalty = $prices['SUPPLIER_PENALTY'];
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT'];
			$rstlsPenalty = $prices['RSTLS_PENALTY'];
		}

		$receiptOptions = [
			'INVOICE_ID' => $invoiceId,
			'PRICE' => $this->getFullPriceForReceipt(),
			'DEAL_ID' => $this->dealId,
			'SUPPLIER_PENALTY' => $supplierPenalty,
			'SUPPLIER_REPLACEMENT' => $supplierReplacement,
			'RSTLS_PENALTY' => $rstlsPenalty,
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();

		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'INCOME',
			'AGENT_SUPPLIER_LR',
			$this->strategyTypeByDate
		);

		$receiptStrategy->setEmail(\COption::GetOptionString('brs.receiptofd', 'RECEIPT_SERVICE_EMAIL'));

		return $receiptStrategy;
	}

	private function getReceiptFieldsBuyerAgent(array $prices, array $finCard): ReceiptStrategy\AbstractReceipt
	{
		$inn = $this->getRequisiteInn();
		if ($inn === null) {
			throw new Exception('Company not INN');
		}

		if ($prices['CURRENCY']) {
			$priceSupplier = $prices['SUPPLIER_CURRENCY'] * $this->currencyRate;
			$service = $prices['SERVICE_CURRENCY'] * $this->currencyRate;
			$supplierPenalty = $prices['SUPPLIER_PENALTY_CURRENCY'] * $this->currencyRate;
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT_CURRENCY'] * $this->currencyRate;
			$rstlsPenalty = $prices['RSTLS_PENALTY_CURRENCY'] * $this->currencyRate;
		} else {
			$priceSupplier = $prices['SUPPLIER'];
			$service = $prices['SERVICE'];
			$supplierPenalty = $prices['SUPPLIER_PENALTY'];
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT'];
			$rstlsPenalty = $prices['RSTLS_PENALTY'];
		}

		$invoiceId = implode(
			'_',
			[
				$this->strategyTypeByDate,
				'DEAL',
				$this->dealId,
				(new DateTime())->format('H_i_s_d_m_Y')
			]
		);

		$receiptOptions = [
			'INVOICE_ID' => $invoiceId,
			'PRICE' => $this->getFullPriceForReceipt(),
			'DEAL_ID' => $this->dealId,
			'SERVICE_RS_TLS' => $service,
			'SUPPLIER' => $priceSupplier,
			'SUPPLIER_VAT' => $finCard['SUPPLIER_VAT'],
			'COMPANY_INN' => $inn,
			'COMPANY_NAME' => $this->companyName,
			'PRODUCT_NAME' => $this->getNomenclature(),
			'SUPPLIER_PENALTY' => $supplierPenalty,
			'SUPPLIER_REPLACEMENT' => $supplierReplacement,
			'RSTLS_PENALTY' => $rstlsPenalty,
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();

		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'INCOME',
			'AGENT_BUYER',
			$this->strategyTypeByDate
		);

		return $receiptStrategy;
	}

	private function getReceiptFieldsServices(array $prices, array $finCard): ReceiptStrategy\AbstractReceipt
	{
		$invoiceId = implode(
			'_',
			[
				$this->strategyTypeByDate,
				'DEAL',
				$this->dealId,
				(new DateTime())->format('H_i_s_d_m_Y')
			]
		);

		if ($prices['CURRENCY']) {
			$supplierPenalty = $prices['SUPPLIER_PENALTY_CURRENCY'] * $this->currencyRate;
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT_CURRENCY'] * $this->currencyRate;
			$rstlsPenalty = $prices['RSTLS_PENALTY_CURRENCY'] * $this->currencyRate;
		} else {
			$supplierPenalty = $prices['SUPPLIER_PENALTY'];
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT'];
			$rstlsPenalty = $prices['RSTLS_PENALTY'];
		}

		$receiptOptions = [
			'INVOICE_ID' => $invoiceId,
			'PRICE' => $this->getFullPriceForReceipt(),
			'DEAL_ID' => $this->dealId,
			'PRODUCT_NAME' => $this->getNomenclature(),
			'SUPPLIER_VAT' => $finCard['SUPPLIER_VAT'],
			'CURRENCY' => $prices['CURRENCY_ID'],
			'SUPPLIER_PENALTY' => $supplierPenalty,
			'SUPPLIER_REPLACEMENT' => $supplierReplacement,
			'RSTLS_PENALTY' => $rstlsPenalty,
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();
		
		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'INCOME',
			'SERVICE',
			$this->strategyTypeByDate
		);

		$receiptStrategy->setEmail(\COption::GetOptionString('brs.receiptofd', 'RECEIPT_SERVICE_EMAIL'));

		return $receiptStrategy;
	}

	private function getReceiptFieldsRsTlsServiceFee(array $prices, array $finCard): ReceiptStrategy\AbstractReceipt
	{
		$invoiceId = implode(
			'_',
			[
				$this->strategyTypeByDate,
				'DEAL',
				$this->dealId,
				(new DateTime())->format('H_i_s_d_m_Y')
			]
		);

		if ($prices['CURRENCY']) {
			$supplierPenalty = $prices['SUPPLIER_PENALTY_CURRENCY'] * $this->currencyRate;
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT_CURRENCY'] * $this->currencyRate;
			$rstlsPenalty = $prices['RSTLS_PENALTY_CURRENCY'] * $this->currencyRate;
		} else {
			$supplierPenalty = $prices['SUPPLIER_PENALTY'];
			$supplierReplacement = $prices['SUPPLIER_REPLACEMENT'];
			$rstlsPenalty = $prices['RSTLS_PENALTY'];
		}

		$receiptOptions = [
			'INVOICE_ID' => $invoiceId,
			'PRICE' => $this->getFullPriceForReceipt(),
			'DEAL_ID' => $this->dealId,
			'SUPPLIER_PENALTY' => $supplierPenalty,
			'SUPPLIER_REPLACEMENT' => $supplierReplacement,
			'RSTLS_PENALTY' => $rstlsPenalty,
		];

		$receiptStrategyCreator = new ReceiptStrategyCreator();

		$receiptStrategy = $receiptStrategyCreator->create(
			$receiptOptions,
			'INCOME',
			'SERVICE_RS_TLS',
			$this->strategyTypeByDate
		);

		$receiptStrategy->setEmail(\COption::GetOptionString('brs.receiptofd', 'RECEIPT_SERVICE_EMAIL'));

		return $receiptStrategy;
	}

	private function getPrice($ID): array
	{
		$price = FinancialCard::getPriceByFinancialCardId($ID);
		return $price;
	}

	private function getFinCard()
	{
		$dbFinCard = FinancialCardTable::getList(
			[
				'filter' => ['=DEAL_ID' => $this->dealId]
			]
		);
		$finCard = $dbFinCard->fetch();
		return $finCard;
	}

	private function isMomentaryProcess(string $finCardType): bool
	{
		$isFinCardType =  in_array($finCardType, ['LR_SUPPLIER_AGENT', 'RS_TLS_SERVICE_FEE']);
		$isDealCategory = in_array(
			$this->deal['CATEGORY_ID'],
			[
				Deal\Visa::CATEGORY_ID,
				Deal\Railway::CATEGORY_ID,
				Deal\Avia::CATEGORY_ID,
				Deal\Insurance::CATEGORY_ID,
				Deal\Info::CATEGORY_ID,
				Deal\Tickets::CATEGORY_ID,
				Deal\LostItems::CATEGORY_ID,
				Deal\Translation::CATEGORY_ID
			]
		);

		return $isFinCardType || $isDealCategory;
	}

	private function returnFinalPayment($paymentType, $returnPriceProduct, $returnPriceCollectingRsTls, $returnPriceCollectingSupplier, bool $isRealReturn): void
	{
		$finCardType = FinancialCard::getFinCardTypeById($this->finCard['ID']);
		$this->strategyTypeByDate = $paymentType;

		$receiptStrategy = new ReturnFinalPayment($finCardType);
		$receiptStrategy->setEmail(\COption::GetOptionString('brs.receiptofd', 'RECEIPT_SERVICE_EMAIL'));
		$receiptStrategy->setDealId($this->dealId);

		$receiptStrategy->setProductName($this->getNomenclature());

		if ($isRealReturn) {
			$receiptStrategy->setRealReturn();
		}

		$isRequiredCompanyFields = in_array(
			$finCardType,
			[FinancialCardTable::SCHEME_BUYER_AGENT, FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT]
		);

		if ($isRequiredCompanyFields) {
			$receiptStrategy->setSupplierInn($this->getRequisiteInn());
			$receiptStrategy->setSupplierName($this->companyName);
		}

		$receiptStrategy->setPriceProduct($returnPriceProduct);
		$receiptStrategy->setPriceCollectingRsTls($returnPriceCollectingRsTls);
		$receiptStrategy->setPriceCollectingSupplier($returnPriceCollectingSupplier);

		if ($paymentType === ReceiptTable::PAYMENT_TYPE_PREPAYMENT) {
			$receiptStrategy->setPaymentPrepayment();
		}

		if ($paymentType === ReceiptTable::PAYMENT_TYPE_FULL_PAYMENT) {
			$receiptStrategy->setPaymentFullPayment();
		}

		$receiptStrategy->setPaymentId(0);

		$manager = new Receipt\Manager;

		$manager->setDealId($this->dealId);
		$manager->setPaymentId(0);
		$manager->setStrategy($receiptStrategy);

		$manager->create(); // создаём чек в системе

	}

	private function getDifferencePriceCardCorrection(): array
	{
		$prevCard = CorrectionCard::getPevCardsByDealId($this->dealId)[0];

		$differencePrice = [];

		foreach ($this->price as $name => $value) {
			$isPenalty = in_array($name, ['SUPPLIER_PENALTY', 'SUPPLIER_REPLACEMENT', 'RSTLS_PENALTY']);
			if (is_float($value) && !$isPenalty) {
				$differencePrice[$name]	= $value - $prevCard['PRICE'][$name];
			} else {
				$differencePrice[$name]	= $value;
			}
		}

		return $differencePrice;
	}

	private function getFullPriceForReceipt()
	{
		if(!$this->isPreReceipt){
			if($credit = Credit::infoByDeal($this->dealId)){ // проверяем есть ли в сделке созданный активный кредит
				return $credit['AMOUNT_TOTAL']; // заполняем сумму чека из поля "Всего к оплате" кредита
			} else if ($this->isCorrection && $this->strategyTypeByDate === 'PREPAYMENT') {
				$prevCard = CorrectionCard::getPevCardsByDealId($this->dealId)[0];

				if ($this->price['RESULT_CURRENCY']) {
					return $this->price['RESULT_CURRENCY'] * $this->currencyRate - $prevCard['PRICE']['RESULT_CURRENCY'] * $this->currencyRate;
				} else {
					return $this->price['RESULT'] - $prevCard['PRICE']['RESULT'];
				}
			} else {
				return PaymentTransaction::getAllIncomeTransactionByDealId($this->dealId);
			}
		} else {

			if($this->isCorrection && ($this->strategyTypeByDate === 'PREPAYMENT'  || $this->strategyTypeByDate === 'CREDIT')) {
				$prevCard = CorrectionCard::getPevCardsByDealId($this->dealId)[0];

				if ($this->price['RESULT_CURRENCY']) {
					return $this->price['RESULT_CURRENCY'] * $this->currencyRate - $prevCard['PRICE']['RESULT_CURRENCY'] * $this->currencyRate;
				} else {
					return $this->price['RESULT'] - $prevCard['PRICE']['RESULT'];
				}
			}  else {
				$sumPayment = PaymentTransaction::getAllIncomeTransactionByDealId($this->dealId);

				if($sumPayment != $this->price['RESULT']) {
					return $this->price['RESULT'] ;
				}
				return $sumPayment;
			}
		}

	}

	public function getAgentFunction(): string
	{
		return 'Brs\ReceiptOfd\Agent\ReceiptOfdAgent::printReceiptFullPayment(' . $this->dealId . ');';
	}

	public function clearAgentReceiptFullPayment()
	{
		$dbAgents = \CAgent::GetList([], ['=NAME' => 'Brs\ReceiptOfd\Agent\ReceiptOfdAgent::printReceiptFullPayment(' . $this->dealId .');']);
		while ($agent = $dbAgents->Fetch()) {
			\CAgent::Delete($agent['ID']);
		}
	}
}
