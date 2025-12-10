<?php

	namespace Brs\Point\Agent;

	use Bitrix\Main\Loader;

	use Brs\Entities\Deal;
	use Brs\Main\Crm\Deal\Price;
	use Brs\Main\Model\Orm\Crm\Deal\DealTable;
	use Brs\Exchange1C\Models\AccountingEntryTable;
	use Brs\FinancialCard\Models\FinancialCardTable;
	use Brs\Exchange1c\AccountingEntry\ServiceActBuyer;
	use Brs\Exchange1c\AccountingEntry\ServiceActSupplier;
	use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;

	/**
	 * Агент работает с бух. проводками по баллам.
	 */
	class AccountingEntry {

		/*
		 * Создаём проводки реализации и поступления при оплате баллами.
		 * 
		 * @param int $dealId идентификатор сделки (при вызове на сделку)
		 * @return string
		 */
		static function createRealizationEntrance(int $dealId = 0) : string {

			$controlStageList = \getStageListOfDealConstant('STATUS_CONTROL_DEAL'); // получаем список кодов стадий "Контроль сделки" из различных направлений сделок

			$filter = [

				'STAGE_ID' => $controlStageList, // в стадии "Контроль сделки"

				'<='.Deal::DATE_SERVICE_PROVISION => (new \DateTime)->format('d.m.Y'), // дата оказания услуг равна текущей дате

			];

			if($dealId > 0){
				$filter['ID'] = $dealId;
			}

			// получаем список сделок
			$dealList = DealTable::getList([

				'select' => [
					'ID', 'STAGE_ID', Deal::DATE_SERVICE_PROVISION
				],

				'filter' => $filter,

				'order' => [
					'ID' => 'DESC'
				]

			])->fetchAll();

			if(!$dealList){
				return __METHOD__ . '();';
			}

			self::createRealizationEntranceOfDealList($dealList); // создаём проводки по найденным сделкам
			
			return __METHOD__.'();';

		}

		/**
		 * Создаём проводки реализации и поступления при оплате баллами на основе сделок.
		 * 
		 * @param array $dealList список сделок
		 * @return void
		 */
		static function createRealizationEntranceOfDealList(array $dealList): void {

			Loader::includeModule('brs.incomingpaymentecomm');
			Loader::includeModule('brs.financialcard');
			Loader::includeModule('brs.exchange1c');

			foreach($dealList as $deal){

				$paymentPointLast = PaymentTransactionTable::getList([

					'select' => [
						'ID', 'DEAL_ID', 'PAYMENT_BY_POINT'
					],

					'filter' => [
						'DEAL_ID' => $deal['ID'],
						'PAYMENT_BY_POINT' => true
					],

					'order' => [ 'ID' => 'DESC' ],

					'limit' => 1

				]);

				if($paymentPointLast->getSelectedRowsCount() == 0){
					continue;
				}

				$amountDebt = Price::getAmountDebtRub($deal['ID']); // сумма задолженности по текущей сделке

				if($amountDebt > 0){ // если сумма задолженности больше 0
					continue;
				}

				$payment = $paymentPointLast->fetchObject();

				// ищем финансовую карту по текущей сделке
				$financialCard = FinancialCardTable::getList([

					'select' => [
						'ID', 'SCHEME_WORK'
					],

					'filter' => [
						'DEAL_ID' => $deal['ID']
					],

					'limit' => 1

				]);

				if($financialCard->getSelectedRowsCount() == 0){ // если финансовая карта не найдена
					continue;
				}
				
				$financialCard = $financialCard->fetch();

				if(!in_array($financialCard['SCHEME_WORK'], [ FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT, FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE ])){

					// создана ли уже для этой сделки проводка "Акт поставщика"?
					$isServiceActSupplier = AccountingEntryTable::getList([

						'select' => [ 'ID' ],

						'filter' => [
							'ENTITY' => 'SERVICE_ACT_SUPPLIER',
							'DEAL_ID' => $deal['ID']
						],

						'limit' => 1

					])->getSelectedRowsCount() > 0;

					if(!$isServiceActSupplier){ // если проводка "Акт поставщика" не создана
						ServiceActSupplier::handler($payment, []); // создаём проводку акт поставщика
					}

				}

				// создана ли уже для этой сделки проводка "Акт покупателя"?
				$isServiceActBuyer = AccountingEntryTable::getList([

					'select' => [ 'ID' ],

					'filter' => [
						'ENTITY' => 'SERVICE_ACT_BUYER',
						'DEAL_ID' => $deal['ID']
					],

					'limit' => 1

				])->getSelectedRowsCount() > 0;

				if(!$isServiceActBuyer){ // если проводка "Акт покупателя" не создана
					ServiceActBuyer::handler($payment, []); // создаём проводку акт покупателя
				}

			}

		}

		/**
		 * Получаем список идентификаторов из сделок.
		 * 
		 * @param array $dealList
		 * @return array
		 */
		static function getDealIdListOfDealList(array $dealList): array {

			$idList = []; 

			foreach($dealList as $deal){
				$idList[] = $deal['ID'];
			}

			return $idList;

		}

	}
