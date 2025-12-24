<?php


namespace Brs\FinancialCard\Controller;


use Bitrix\Crm\DealTable;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\RestException;
use Brs\Entities\Deal\Avia;
use Brs\Factories\DealCategoryFactory;
use Brs\FinancialCard\Agent\DelayedRefundAgent;
use Brs\FinancialCard\AuditionRefundCardNotify;
use Brs\FinancialCard\Models\EO_RefundCard;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\FinancialCard\Models\RefundCardTable;
use Brs\FinancialCard\Repository\CorrectionCard;
use Brs\FinancialCard\Repository\Deposit;
use Brs\FinancialCard\Repository\FinancialCard;
use Brs\FinancialCard\Repository\RefundCard;
use Brs\IncomingPaymentEcomm\AverageRate;
use Brs\Main\Soap\Eis;
use Brs\ReceiptOfd\FinancialCardPayment;
use Brs\Point\Model\Orm\PointTable;
use Brs\Currency\Models\CurrencyPointTable;
use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;
use Ramsey\Uuid\Uuid;
use Brs\Main\Model\Orm\Crm\Contact\ContactPropertyTable;
use Brs\Mom\Synchronized;
use Brs\Mom\Import\Order\File\Xml;
use Brs\FinancialCard\Credit;
use CCrmDeal;
use Exception;
use Throwable;

class RefundController extends Controller
{

	public function configureActions(): array {
		return [
			'changeStatusRefundCard' => [
				'prefilters' => []
			],
			'changeCheckTotalAmountIncorrect' => [
				'prefilters' => []
			],
			'changeIsRetryCheckTotalAmount' => [
				'prefilters' => []
			],
			'sendTeamLeaderRefound' => [
				'prefilters' => []
			],
		];
	}

	/**
	 * Сохраняем поля карты возврата из параметров формы.
	 * 
	 * @param array $form
	 * @return void
	 * @throws RestException
	 */
	public function saveAction(array $form): void {

		Loader::includeModule('brs.mom');

		try {

			$data = array_combine(array_column($form, 'name'), array_column($form, 'value'));

			RefundCard::save($data);

			Synchronized\Deal::createQueue($data['DEAL_ID']); // добавляем текущую сделку в очередь на синхронизацию с МОМ

		} catch (Throwable $throwable) {
			throw new RestException($throwable->getMessage());
		}

	}

	/**
	 * Устанавливаем в параметры проверки сумм. Вызывается при щелчке на кнопку "Суммы неверны".
	 * 
	 * @param int $refundCardId
	 * @return void
	 */
	public function changeCheckTotalAmountIncorrectAction(int $refundCardId): void {

		$refundCard = RefundCardTable::wakeUpObject($refundCardId);

		$refundCard->set('IS_CORRECT_AMOUNT_ALL', false); // устанавливаем значение «Нет» в поле «Суммы верны?» (IS_CORRECT_AMOUNT_ALL)
		$refundCard->set('IS_RETRY_CHECK_TOTAL_AMOUNT', true); // устанавливаем значение "Да" в поле «Повторная проверка сумм?» (IS_RETRY_CHECK_TOTAL_AMOUNT)

		$refundCard->save();

	}

	/**
	 * Устанавливаем в параметры проверки сумм. Вызывается при щелчке на кнопку "Повторно отправить на проверку".
	 * 
	 * @param int $refundCardId
	 * @return void
	 */
	public function changeIsRetryCheckTotalAmountAction(int $refundCardId): void {

		$refundCard = RefundCardTable::wakeUpObject($refundCardId);

		$refundCard->set('IS_CORRECT_AMOUNT_ALL', false); // устанавливаем значение "Нет" в поле «Суммы верны?» (IS_CORRECT_AMOUNT_ALL)
		$refundCard->set('IS_RETRY_CHECK_TOTAL_AMOUNT', false); // устанавливаем значение "Нет" в поле «Повторная проверка сумм?» (IS_RETRY_CHECK_TOTAL_AMOUNT)

		$refundCard->save();

	}

	public function changeStatusRefundCardAction(int $refundCardId, string $status, string $reload = 'true')
	{
		global $DB;
		$DB->StartTransaction();

		try {
			if (!key_exists($status, RefundCard::AUDITION_STATUS)) {
				throw new Exception('Error status refund card "' . $status . '"');
			}

			Loader::includeModule('brs.exchange1c');
			Loader::includeModule('brs.receiptofd');

			$reload = $reload === 'true';

			$refundCard = RefundCardTable::wakeUpObject($refundCardId);
			
			$refundCard->fill('DEAL_ID');
			$refundCard->fill('PAYMENT_TYPE');
			$refundCard->fill('FIN_CARD_ID');
			$refundCard->fill('IS_CORRECTION_CARD');

			$refundCard->setStatus($status);

			$dealId = $refundCard->getDealId();

			$auditionRefundCardNotify = new AuditionRefundCardNotify();

			if($status === RefundCardTable::STATUS_CHECK_TOTAL_AMOUNT_VERIFIED){ // если установлен статус "Суммы проверены"

				$refundCard->setIsCorrectAmountAll(true); // устанавливаем значение "Да" в поле "Cуммы верны?"

				Credit\Repository::updateOfRefundCardCheckTotal($refundCard->getId()); // обновляем кредит по итогу проверки сумм в карте возврате

				$isExistFinCard = $refundCard->getFinCardId() > 0;
				$isCorrectionCard = $refundCard->getIsCorrectionCard();

				if ($isExistFinCard && !$isCorrectionCard) {
					// Проводка REFUND_REALIZATION RefundRealization

					\Brs\Exchange1c\AccountingEntry\RefundRealization::handler(
						[
							'REFUND_ID' => $refundCard->getId()
						],
						[]
					);

					// Проводка REFUND_INCOME RefundIncome
					$finCardType = FinancialCard::getFinCardTypeById($refundCard->getFinCardId());

					$isCorrectFinCardType = in_array(
						$finCardType,
						[
							FinancialCardTable::SCHEME_BUYER_AGENT,
							FinancialCardTable::SCHEME_PROVISION_SERVICES
						]
					);

					if ($isCorrectFinCardType) {
						\Brs\Exchange1c\AccountingEntry\RefundIncome::handler(
							[
								'REFUND_ID' => $refundCard->getId()
							],
							[]
						);
					}
				}
			}

			if ($status === RefundCardTable::STATUS_CONFIRMED_AGREEMENT) {
				$auditionRefundCardNotify->sendRefundCanBeTakenTtoWork($refundCardId);
			}

			if ($status === RefundCardTable::STATUS_WORK) {
				$refundCard->setAuditorId(CurrentUser::get()->getId());
			}

			$isNotPaymentPoints = $refundCard->getPaymentType() == '';
			
			if($status === RefundCardTable::STATUS_COMPLETED && $isNotPaymentPoints){ // если устанавливается статус "Успешно завершено" и карта возврата авансовая, а не по баллам

				$refundCard->fill('DIRECTION_TYPE');
				$refundCard->fill('IS_CORRECTION_CARD');

				$auditionRefundCardNotify->sendRefundCompleted($refundCardId);
				$this->addAmountToDeposit($refundCard);

				$this->makeReturnAndPrintReceipt($refundCard); // создаёт платёж возврата и чеки
				
				if(Credit\Repository::isExist($refundCard->getDealId())){ // проверяем, есть ли активный кредит
					Credit\Repository::updateOfRefundCard($refundCard->getId()); // обновляем кредит на основе карты возврата (последнего платежа по ней)
				}

				(new DelayedRefundAgent())->clearDelayDate($refundCardId);

				if($refundCard->getIsCorrectionCard()){

					// Если это карта коррекции то агенту не нужно вручную закрывать карту возврата
					// - переводим её автоматом в статус закрыто
					$refundCard->setStatus(RefundCardTable::STATUS_CLOSE);

					$correctionCard = new CorrectionCard();
					$correctionCard->runFinishRefund($dealId);

					Loader::includeModule('brs.mom');

					Xml::updateServiceIsFinancialData($dealId); // устанавливаем значение "Да" в параметр "Участвует в расчётах?" во все услуги МОМ сделки

				}

			} else if ($status === RefundCardTable::STATUS_COMPLETED && $refundCard->getPaymentType() == RefundCardTable::PAYMENT_TYPE_POINT){

				$refundCard->fill('DIRECTION_TYPE');

				$auditionRefundCardNotify->sendRefundCompleted($refundCardId);

				$financialCardPayment = new FinancialCardPayment($dealId);
				$financialCardPayment->makeRefundPoint();

				$this->makeReturnRefoundPoint($refundCard);
			}

			$result = $refundCard->save();

			if ($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			}

			$DB->Commit();

			if($reload){
				$response = new Redirect("/crm/deal/details/$dealId/?showFinCard=y");
				$response->send();
			}
		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	public function createDelayRefundAction(int $refundCardId, string $date)
	{
		$refundCard = RefundCardTable::getById($refundCardId)->fetchObject();
		$refundCard->setStatus(RefundCardTable::STATUS_DELAY);
		$refundCard->setDelayDate(new DateTime($date));
		$refundCard->save();
		(new DelayedRefundAgent())->addAgent($refundCardId, new DateTime($date));
	}

	public function changeDelayRefundAction(int $refundCardId, string $date)
	{
		$refundCard = RefundCardTable::getById($refundCardId)->fetchObject();
		$refundCard->setDelayDate(new DateTime($date));
		$refundCard->save();
		(new DelayedRefundAgent())->changeDelayDate($refundCardId, new DateTime($date));
	}

	/**
	 * Отправляем карту возврата ТимЛидеру
	 *
	 * @global type $DB
	 * @param int $refundCardId
	 * @throws RestException
	 * @throws Exception
	 */
	public function sendTeamLeaderRefoundAction(int $refundCardId, string $status, string $reload = 'true'){

		global $DB;
		$DB->StartTransaction();

		try {

			$reload = $reload === 'true';

			$refundCard = RefundCardTable::wakeUpObject($refundCardId);
			$refundCard->fill('DEAL_ID');
			$refundCard->setStatus($status);
			$refundCard->setPaymentType(RefundCardTable::PAYMENT_TYPE_POINT);
			$dealId = $refundCard->getDealId();

			$auditionRefundCardNotify = new AuditionRefundCardNotify();

			if ($status === RefundCardTable::STATUS_CONFIRMED_TEAMLEADER) {
				$auditionRefundCardNotify->sendTeamLeaderRefundCanBeTakenTtoWork($refundCardId);
			}

			if ($status === RefundCardTable::STATUS_WORK_TEAMLEADER) {
				$refundCard->setAuditorId(CurrentUser::get()->getId());
			}

			if ($reload) {
				$response = new Redirect("/crm/deal/details/$dealId/?showFinCard=y");
				$response->send();
			}

			$result = $refundCard->save();

			if($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			}

			$DB->Commit();

		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	public function cancelAction(int $dealId)
	{
		global $DB;
		$DB->StartTransaction();

		try {
			Loader::includeModule('crm');
			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('brs.receiptofd');

			$dbPrevRefundCard = RefundCardTable::getList(
				[
					'filter' => ['DEAL_ID' => $dealId],
					'select' => ['ID']
				]
			);
			$prevRefundCard = $dbPrevRefundCard->fetch();
			$refundCard = RefundCardTable::wakeUpObject($prevRefundCard['ID']);
			$refundCard->fill('DEAL_ID');
			$refundCard->fill('DEAL_STATUS_BEFORE_RETURN');
			$refundCard->setCanceledRefundDealId($refundCard->getDealId());
			$refundCard->setDealId(0);

			$refundCard->getDealStatusBeforeReturn();

			$dealFields = ['STAGE_ID' => $refundCard->getDealStatusBeforeReturn()];
			(new CCrmDeal())->Update($dealId, $dealFields);

			$result = $refundCard->save();

			if ($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			} else {
				$auditionRefundCardNotify = new AuditionRefundCardNotify();
				$auditionRefundCardNotify->sendCancelRefund($prevRefundCard['ID']);
				(new DelayedRefundAgent())->clearDelayDate($prevRefundCard['ID']);

				FinancialCard::restoreFinCardPrintReceiptAfterCancellationRefund($dealId);
			}

			$DB->Commit();
		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	public function cancelTeamLeaderAction(int $dealId, string $message)
	{
		global $DB;
		$DB->StartTransaction();

		try {
			Loader::includeModule('crm');

			$dbPrevRefundCard = RefundCardTable::getList(
				[
					'filter' => ['DEAL_ID' => $dealId],
					'select' => ['ID']
				]
			);
			$prevRefundCard = $dbPrevRefundCard->fetch();
			$refundCard = RefundCardTable::wakeUpObject($prevRefundCard['ID']);
			$refundCard->fill('DEAL_ID');
			$refundCard->fill('DEAL_STATUS_BEFORE_RETURN');
			$refundCard->setCanceledRefundDealId($refundCard->getDealId());
			$refundCard->setDealId(0);

			$refundCard->getDealStatusBeforeReturn();

			$dealFields = ['STAGE_ID' => $refundCard->getDealStatusBeforeReturn()];
			(new CCrmDeal())->Update($dealId, $dealFields);

			$result = $refundCard->save();

			if ($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			} else {
				$auditionRefundCardNotify = new AuditionRefundCardNotify();
				$auditionRefundCardNotify->sendCancelTeamLeaderRefund($prevRefundCard['ID'], $message);
			}

			$DB->Commit();
		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	public function setSupplierPaymentAction(int $refundCardId, string $status)
	{
		global $DB;
		$DB->StartTransaction();

		try {
			switch ($status) {
				case 'true':
					$status = RefundCardTable::SUPPLIER_STATUS_PAYMENT_EXIST;
					break;
				case 'false':
				default:
					$status = RefundCardTable::SUPPLIER_STATUS_PAYMENT_NOT_EXIST;
					break;
			}

			$refundCard = RefundCardTable::wakeUpObject($refundCardId);
			$refundCard->setSupplierStatus($status);

			$result = $refundCard->save();

			if ($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			}

			$DB->Commit();
		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	/**
	 * Сохраняет обязательные поля перед отправкой карты выозврата в работу.
	 * 
	 * @global \Brs\FinancialCard\Controller\type $DB
	 * @param int $refundCardId
	 * @param string $supplierStatus
	 * @param string $directionType
	 * @throws RestException
	 * @throws Exception
	 */
	public function saveRequiredForStatusInWorkAction(int $refundCardId, string $supplierStatus, string $directionType){

		global $DB;

		$DB->StartTransaction();

		try {
			
			$refundCard = RefundCardTable::wakeUpObject($refundCardId);

			$refundCard->setSupplierStatus($supplierStatus);
			$refundCard->setDirectionType($directionType);

			$result = $refundCard->save();

			if($mess = $result->getErrorMessages()){
				throw new Exception($mess);
			}

			$DB->Commit();

		} catch (Throwable $throwable) {

			$DB->Rollback();

			throw new RestException($throwable->getMessage());

		}

	}

	public function setDirectionTypeAction(int $refundCardId, string $type)
	{
		global $DB;
		$DB->StartTransaction();

		try {
			if (!in_array($type, [RefundCardTable::DIRECTION_TYPE_CARD, RefundCardTable::DIRECTION_TYPE_INVOICE])) {
				throw new RestException('Not valid DIRECTION_TYPE');
			}

			$refundCard = RefundCardTable::wakeUpObject($refundCardId);
			$refundCard->setDirectionType($type);

			$result = $refundCard->save();

			if ($mess = $result->getErrorMessages()) {
				throw new Exception($mess);
			}

			$DB->Commit();
		} catch (Throwable $throwable) {
			$DB->Rollback();
			throw new RestException($throwable->getMessage());
		}
	}

	public function sendMailClientContractAction(int $dealId, string $email)
	{
		$result = Event::sendImmediate(
			[
				'EVENT_NAME' => 'CLIENT_REFUND_CONTRACT',
				'LID' => SITE_ID,
				'C_FIELDS' => [
// TODO: проверить будет ли корректно уходить почта от конкретных менеджеров
// 'FROM' => $user['EMAIL'],
					'TO' => $email,
					'SUBJECT' => '[deal-' . $dealId . ']' . ' шаблон документа возврата',
					'MESS' => 'Распечатайте и распишитесь, после отправьте в ответе письмо'
				]
			]
		);

		if ($result !== 'Y') {
			throw new RestException('Email not send');
		}
	}

	public function makeRetryRefundAction(int $refundCardId)
	{
		Loader::includeModule('brs.receiptofd');
		Loader::includeModule('brs.incomingpaymentecomm');
		RefundCard::makeRetryRefundEcommTransaction($refundCardId);
	}

	public function changeFinDepartmentToSentAction(int $refundCardId)
	{
		$refundCard = RefundCardTable::getById($refundCardId)->fetchObject();
		$refundCard->setStatusFinDepartment(RefundCardTable::STATUS_FIN_DEPARTMENT_SENT);
		$refundCard->save();
	}

	public function changeReturnDirectionToInvoiceAction(int $refundCardId)
	{
		$refundCard = RefundCardTable::getById($refundCardId)->fetchObject();
		$refundCard->setStatus(RefundCardTable::STATUS_WORK);
		$refundCard->setDirectionType(RefundCardTable::DIRECTION_TYPE_INVOICE);
		$refundCard->save();
	}

	public function changeFinDepartmentToReceivedAction(int $refundCardId)
	{
		$refundCard = RefundCardTable::getById($refundCardId)->fetchObject();
		$refundCard->setStatusFinDepartment(RefundCardTable::STATUS_FIN_DEPARTMENT_RECEIVED);
		$refundCard->save();
	}

	private function getFilesArray(array $form, string $fileName): array
	{
		$filesId = [];

		foreach ($form as $item) {
			if ($item['name'] === $fileName . '[]') {
				$filesId[] = $item['value'];
			}
		}

		foreach ($form as $item) {
			if ($item['name'] === $fileName . '_del[]') {
				$fileId = $item['value'];
				unset($filesId[array_search($fileId, $filesId)]);
			}
		}

		return $filesId;
	}

	private function setClientStatementFiles(EO_RefundCard $refundCard, array $filesId)
	{
		$refundCard->setClientStatement($filesId);

		if (!empty($filesId)) {
			$this->changeStatusConfirmedClient($refundCard);
		}
	}

	private function setFinDepartmentStatementFiles(EO_RefundCard $refundCard, array $filesId)
	{
		$refundCard->setFinDepartmentStatement($filesId);
	}

	private function changeStatusConfirmedClient(EO_RefundCard $refundCard)
	{
		Loader::includeModule('crm');

		if ($refundCard->getStatus() === RefundCardTable::STATUS_AWAITING_DOCUMENT_FROM_CLIENT) {
			$refundCard->setStatus(RefundCardTable::STATUS_CONFIRMED_CLIENT);

			$dealId = $refundCard->getDealId();
			$deal = DealTable::getByPrimary($dealId, ['select' => ['STAGE_ID']])->fetch();
			$dealStageId = $deal['STAGE_ID'];
			$refundStatus = DealCategoryFactory::getClassByDealId($dealId)::STATUS_REFUND;

			if ($dealStageId === $refundStatus) {
				// Если сделка в статусе возврат - то меняем статус на возврат подтверждён
				$this->setDealStatusRefundConfirmed($dealId);
			}
		}
	}

	private function setDealStatusRefund(int $dealId)
	{
		$dealCategoryClass = DealCategoryFactory::getClassByDealId($dealId);
		$dealFields['STAGE_ID'] = $dealCategoryClass::STATUS_REFUND;
		(new CCrmDeal())->Update($dealId, $dealFields);
	}

	private function setDealStatusRefundConfirmed(int $dealId)
	{
		$dealCategoryClass = DealCategoryFactory::getClassByDealId($dealId);
		$dealFields['STAGE_ID'] = $dealCategoryClass::STATUS_REFUND_CONFIRMED;
		(new CCrmDeal())->Update($dealId, $dealFields, true, true, ['DISABLE_REQUIRED_USER_FIELD_CHECK' => true]);
	}

	public function addAmountToDeposit(EO_RefundCard $refundCard)
	{
		$refundCard->fill('RETURN_DEPOSIT');
		$refundCard->fill('DEAL_ID');
		$amount = $refundCard->getReturnDeposit();
		if ($amount > 0) {
			Loader::includeModule('crm');
			$deal = DealTable::getById($refundCard->getDealId())->fetch();
			$clientId = $deal['CONTACT_ID'];

			Deposit::addAmount($clientId, $amount, $refundCard->getId());
		}
	}

	/**
	 * Метод возвращает баллы на аккаунт клиента.
	 *
	 * @param EO_RefundCard $refundCard
	 * @throws RestException
	 */
	private function makeReturnRefoundPoint(EO_RefundCard $refundCard){

		Loader::includeModule('brs.point');
		Loader::includeModule('brs.currency');
		Loader::includeModule('brs.incomingpaymentecomm');

		// получаем массив сделки
		$deal = DealTable::getById($refundCard->getDealId())->fetch();

		// получаем идентификатор клиента КС
		$contactProperty = ContactPropertyTable::getByPrimary($deal['CONTACT_ID']);

		// если не созданы свойства контакта
		if($contactProperty->getSelectedRowsCount() == 0){
			throw new RestException('У контакта не заполнены свойства.', 'NOT_PROPERY_CONTACT');
		}

		$contactProperty = $contactProperty->fetchObject();

		$contactPropertyKsId = $contactProperty->get(\Brs\Entities\Contact::KS_ID);

		if(empty($contactPropertyKsId)){
			throw new RestException('У контакта не заполнено поле идентификатор клиента КС.', 'CLIENT_NOT_KS_ID');
		}

		// получаем объект баллов
		$point = PointTable::getByPrimary($deal['CONTACT_ID']);

		if($point->getSelectedRowsCount() == 0){
			throw new RestException('Не были получены баллы для прикреплённого к сделке клиента. Обновите баланс баллов.', 'POINT_NOT_FOUND');
		}

		$point = $point->fetchObject();

		// получаем входящие платежи
		$paymentTransactions = PaymentTransactionTable::getList([
			'filter' => [
				'PAYMENT_TYPE' => PaymentTransactionTable::PAYMENT_TYPE_INCOMING,
				'STATUS' => PaymentTransactionTable::PAYMENT_STATUS_SUCCESS,
				'DEAL_ID' => $refundCard->getDealId(),
				'PAYMENT_BY_POINT' => true
			],
		]);

		if($paymentTransactions->getSelectedRowsCount() == 0){
			throw new RestException('Нельзя сделать возврат баллов, если не было платежей', 'NOT_PAYMENT_TRANSACTIONS');
		}

		$paymentTransactions = $paymentTransactions->fetchCollection();

		$amount = 0; // сумма всех платежей баллами в рублях
		$pointAmount = 0; // сумма всех платежей баллами в баллах

		// обходим каждый платёж и формируем данные для возврата
		foreach($paymentTransactions as $paymentTransaction){
			$amount += $paymentTransaction->getAmount();
			$pointCode = $paymentTransaction->getCurrency();
			$pointAmount += $paymentTransaction->getPointAmount();
		}

		// получаем историю всех бонусных платежей по лицевому счёту клиента
		$pointClientHistory = Eis::call('brs.main', 'GetClientHistory', [
			'clientId' => $contactPropertyKsId,
		]);

		$pointTransactionId = 0; // идентификатор транзакции операции списания баллов с бонусного счёта

		// обходим каждый платёж и формируем данные для возврата
		foreach($paymentTransactions as $paymentTransaction){

			if($pointTransactionId > 0){
				break;
			}

			// обходим историю всех бонусных платежей по лицевому счёту клиента и находим текущий платёж
			foreach($pointClientHistory as $key => $pointHistory){
				if(array_key_exists('dateTime', $pointHistory) && $pointHistory['dateTime'] == $paymentTransaction->getDate()->format('d-m-Y') && $pointHistory['debCred'] == 'D'){

					$pointTransactionId	= $pointHistory['transactionId'];

					break;

				}
			}

		}

		$pointAccountNumber = $point->get(str_replace('IR', 'IMPERIA', $pointCode).'_ACCOUNT_ID'); // идентификатор бонусного счёта

		// если идентификатор бонусного счёта 15 символов, то добавляем к нему 0
		if(strlen($pointAccountNumber) == 15){
			$pointAccountNumber = '0'.$pointAccountNumber;
		}

		// отправляем в EIS запрос на создание возврата
		$refundBonus = Eis::call('brs.main', 'RefundBonus', [
			'clientId' => $contactPropertyKsId,
			'accountNumber' => $pointAccountNumber,
			'loyaltyProgramCode' => str_replace('IR', 'Imperia_R', $pointCode),
			'amount' => $pointAmount,
			'guid' => Uuid::uuid4()->toString(),
			'transactionId' => $pointTransactionId
		]);

		if(is_string($refundBonus)){
			throw new RestException('Ошибка со стороны сервера EIS. Текст ошибки: '.$refundBonus, 'ERROR_EIS');
		} else if(array_key_exists(0, $refundBonus) && current($refundBonus)['message'] != 'OK'){
			throw new RestException('Ошибка со стороны сервера EIS. Текст ошибки: '.current($refundBonus)['message'], 'ERROR_EIS');
		}

		$this->makeReturnPointTransaction($refundCard, $pointCode, $amount, $pointAmount); // создаём платёж на возврат

	}

	/**
	 * Метод создаёт платёж на возврат
	 *
	 * @param EO_RefundCard $refundCard
	 */
	private function makeReturnPointTransaction(EO_RefundCard $refundCard, $pointCode, $amount, $pointAmount){

		Loader::includeModule('brs.exchange1c');
		Loader::includeModule('brs.incomingpaymentecomm');

		$refundCard->fill('DEAL_ID');
		$refundCard->fill('RETURN_CASH');

		$dealId = $refundCard->getDealId();
		$returnCash = $refundCard->getReturnCash();

		if($returnCash == 0){
			throw new RestException('В карте возврата сумма возврата равна нулю', 'ERROR_REFUND_RETURN_CASH_NULL');
		}

		// создаём входящий платёж
		$payment = PaymentTransactionTable::createObject();

		$payment->setPaymentType(PaymentTransactionTable::PAYMENT_TYPE_REFUND);
		$payment->setStatus(PaymentTransactionTable::PAYMENT_STATUS_SUCCESS);

		$payment->setAmount($amount);
		$payment->setPointAmount($pointAmount);
		$payment->setDealId($dealId);

		$payment->setDate((new \DateTime)->format('d.m.Y H:i:s'));

		$payment->setPaymentByPoint(true);
		$payment->setCurrency($pointCode);

		$result = $payment->save();

		if(!$result->isSuccess()){
			throw new RestException('Не удалось создать платёж. Текст ошибки: '.$result->getErrorsMessage(), 'ERROR_MAKE_TRANSACTION');
		}

		\Brs\Exchange1c\AccountingEntry\PointRefund::handler($payment->collectValues()); // создаём проводку возврата баллов

	}

	public function makeReturnAndPrintReceipt(EO_RefundCard $refundCard): array
	{
		Loader::includeModule('brs.incomingpaymentecomm');
		Loader::includeModule('brs.receiptofd');

		$refundCard->fill();

		$isReturnCardEcomm = $refundCard->getDirectionType() === RefundCardTable::DIRECTION_TYPE_CARD;
		$isPaymentNotPoint = $refundCard->getPaymentType() !== RefundCardTable::PAYMENT_TYPE_POINT;
		$returnCash = $refundCard->getReturnCash();
		if ($isPaymentNotPoint && $returnCash > 0) {
			$dealId = $refundCard->getDealId();
			if ($isReturnCardEcomm) {

				$dbDeal = DealTable::getList(
					[
						'filter' => ['=ID' => $dealId],
						'select' => ['CATEGORY_ID']
					]
				);

				$deal = $dbDeal->fetch();

				$averageRate = AverageRate::get($dealId);
				$isCurrencyFinCard = $averageRate && $averageRate['AVERAGE_CURRENCY_ID'] !== 'RUB';

				if ($isCurrencyFinCard) {
					$returnSumRsTlsFee = floatval($refundCard['RS_TLS_FEE_CURRENCY']) * $averageRate['AVERAGE_RATE'] * $averageRate['AVERAGE_RATE_CNT'];
					$returnSumSupplier = floatval($refundCard['SUPPLIER_RETURN_CURRENCY']) * $averageRate['AVERAGE_RATE'] * $averageRate['AVERAGE_RATE_CNT'];
				} else {
					$returnSumRsTlsFee = floatval($refundCard->fill('RS_TLS_FEE'));
					$returnSumSupplier = floatval($refundCard->fill('SUPPLIER_RETURN'));
				}

				$returnSumNomenclature = $returnCash - $returnSumRsTlsFee - $returnSumSupplier;
				$result = RefundCard::addRefundEcommTransaction(
					$dealId,
					$refundCard->getId(),
					$returnCash,
					$returnSumNomenclature,
					$returnSumRsTlsFee,
					$returnSumSupplier
				);
				
				return $result;

			} else {
				$this->createReceiptReturn($dealId);
				return ['STATUS' => 'SUCCESS', 'ERROR_MESS' => []];
			}
		} else {
			return ['STATUS' => 'SUCCESS', 'ERROR_MESS' => []];
		}
	}

	/**
	 * Проверяет были ли напечатаны чеки "Предоплаты" или "Полной оплаты", если да - то печатаем чек возврата на последний из этих напечатанных типов. Иначе печатаем чек возврата на аванс.
	 * 
	 * @param int $dealId идентификатор сделки
	 * @return void
	 */
	private function createReceiptReturn(int $dealId): void {

		Loader::includeModule('brs.financialcard');
		Loader::includeModule('brs.receiptofd');

		$financialCardPayment = new FinancialCardPayment($dealId);

		$isCredit = Credit\Repository::isExistActive($dealId);

		if(!$isCredit){ // если нет активного кредита
			$financialCardPayment->returnDealRefund(); // создаём чек возврата на основе авансового чека
		}

	}

	private function clearAgentThisDeal(int $dealId)
	{
		$dbAgents = \CAgent::GetList([], ['=NAME' => 'Brs\ReceiptOfd\Agent\ReceiptOfdAgent::printReceiptFullPayment(' . $dealId .');']);
		while ($agent = $dbAgents->Fetch()) {
			\CAgent::Delete($agent['ID']);
		}
	}
}
