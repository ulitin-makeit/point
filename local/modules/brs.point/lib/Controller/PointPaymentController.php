<?php

	namespace Brs\Point\Controller;

	use Bitrix\Main\Engine\Controller;
	use Bitrix\Main\Engine\Response\AjaxJson;
	use Bitrix\Main\Error;
	use Bitrix\Main\Loader;
	use Bitrix\Main\ErrorCollection;
	use Ramsey\Uuid\Uuid;

	use Brs\Main\Soap\Eis;
	use Brs\Point\Model\Orm\PointTable;
	use Brs\Currency\Models\CurrencyPointTable;
	use Brs\Exchange1c\AccountingEntry\PointPayment;
	use Brs\IncomingPaymentEcomm\Controller\PayController;
	use Brs\Main\Model\Orm\Crm\Contact\ContactPropertyTable;
	use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;

	/**
	 * Класс контроллер содержащий Ajax методы для работы с оплатой баллов.
	 */
	class PointPaymentController extends Controller {

		/**
		 * Метод списывает баллы с клиента
		 * 
		 * @param int $dealId
		 * @param int $contactId
		 * @param float $amount
		 * @return AjaxJson
		 */
		public function runAction(int $dealId, int $contactId, float $amount, string $rateType): AjaxJson {

			$rateTypeEis = str_replace(['mr_rub', 'imperia_rub'], ['MR', 'Imperia_R'], $rateType); // тип баллов для EIS

			$errCollection = (new ErrorCollection()); // объект ошибок

			Loader::includeModule('crm');
			Loader::includeModule('brs.exchange1c');
			Loader::includeModule('brs.currency');
			Loader::includeModule('brs.incomingpaymentecomm');

			// получаем идентификатор клиента КС
			$contactProperty = ContactPropertyTable::getByPrimary($contactId);

			// если не созданы свойства контакта
			if($contactProperty->getSelectedRowsCount() == 0){

				$errCollection->add([new Error('У контакта не заполнены свойства.', 'NOT_PROPERY_CONTACT')]);

				return AjaxJson::createError($errCollection);

			}

			$contactProperty = $contactProperty->fetchObject();

			$contactPropertyKsId = $contactProperty->get(\Brs\Entities\Contact::KS_ID);

			// если KS_ID не заполнен у контакта
			if(empty($contactPropertyKsId)){

				$errCollection->add([new Error('У контакта не заполнено свойство "Идентификатор клиента КС".', 'EMPTY_KS_ID')]);

				return AjaxJson::createError($errCollection);

			}

			// получаем информацию о баллах лояльности у контакта
			$point = PointTable::getByPrimary($contactId);

			if($point->getSelectedRowsCount() == 0){

				$errCollection->add([new Error('Не удалось получить информацию по баллам лояльности у прикреплённого к сделке клиента.', 'NOT_POINT_CONTACT')]);

				return AjaxJson::createError($errCollection);

			}

			$currencyCode = '';
			$currencyRate = 0;

			$point = $point->fetchObject();

			if($rateType == 'mr_rub'){

				$currencyCode = 'MR';

				$acctNum = $point->getMrAccountId();
				$currencyRate = $point->getMrRate();

			} else if($rateType == 'imperia_rub'){

				$currencyCode = 'IR';

				$acctNum = $point->getImperiaAccountId();
				$currencyRate = $point->getImperiaRate();

			} else {

				$errCollection->add([new Error('Не верно указан тип платежа.', 'NOT_POINT_PAYMENT_TYPE')]);

				return AjaxJson::createError($errCollection);

			}

			// создаём входящий платёж
			$payment = PaymentTransactionTable::createObject();

			$payment->setPaymentType(PaymentTransactionTable::PAYMENT_TYPE_INCOMING);
			$payment->setStatus(PaymentTransactionTable::PAYMENT_STATUS_SUCCESS);

			$payment->setAmount($amount);
			$payment->setDealId($dealId);

			$payment->setDate((new \DateTime)->format('d.m.Y H:i:s'));

			$payment->setPaymentByPoint(true);
			$payment->setCurrency($currencyCode);

			$payment->save();

			PointPayment::handler($payment->collectValues()); // создаём проводку возврата баллов

			// проверяем, указан ли был курс на текущую дату
			$currency = CurrencyPointTable::getList([
				'filter' => [
					'CURRENCY' => $currencyCode,
					'DATE_ACTIVE' => (new \DateTime)->format('d.m.Y')
				]
			]);

			if($currency->getSelectedRowsCount() == 0){
				$currency = CurrencyPointTable::createObject();
			} else {
				$currency = $currency->fetchObject();
			}

			$currency->setCurrency($currencyCode);
			$currency->setRate($currencyRate);
			$currency->setDateActive((new \DateTime)->format('d.m.Y'));

			$currency->save();

			$uuid = Uuid::uuid4()->toString();

			// осуществляем запрос в EIS и списываем с баланса
			$pointEis = Eis::call('brs.main', 'PayWithBonus', [
				'clientId' => $contactPropertyKsId,
				'acctNum' => $acctNum,
				'loyaltyProgramCode' => $rateTypeEis,
				'operationType' => 1,
				'amount' => $amount,
				'guid' => $uuid
			]);

			if(is_string($pointEis) && str_replace('faultstring', '', $pointEis) != $pointEis){

				if(str_replace('web_ks_idclient_conciergeinfo', '', $pointEis) != $pointEis){
					$errCollection->add([new Error('Клиент не зарегистрирован в Бонусной программе', 'EIS_ERROR')]);
				} else if(str_replace('Карта не найдена', '', $pointEis) != $pointEis){
					$errCollection->add([new Error('Карта не найдена', 'EIS_ERROR')]);
				} else {
					$errCollection->add([new Error('Ошибка вызова ЕИС метода. '.$pointEis, 'EIS_ERROR')]);
				}

				return AjaxJson::createError($errCollection);

			}

			// заполнение поля Тип оплаты в сделке
			PayController::updatePropertyDealTypePay('point', $dealId);

			// добавление записи об оплате в историю сделки
			PayController::addEventHistoryCurrency($dealId, $amount, 'point');

			return AjaxJson::createSuccess($pointEis);

		}

	}
