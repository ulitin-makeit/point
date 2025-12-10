<?php

	namespace Brs\Point\Controller;

	use Bitrix\Main\Engine\Controller;
	use Bitrix\Main\Engine\Response\AjaxJson;
	use Bitrix\Main\Error;
	use Bitrix\Main\Loader;
	use Bitrix\Main\ErrorCollection;
	use Bitrix\Crm\DealTable;

	use Brs\Point\Model\Orm\PointTable;
	use Brs\Main\Soap\Eis;
	use Brs\Main\Model\Orm\Crm\Contact\ContactPropertyTable;

	/**
	 * Класс контроллер содержащий Ajax методы для работы с баллами.
	 */
	class PointController extends Controller {

		/**
		 * Метод отдаёт информацию по баллам из сделки или контакта.
		 * 
		 * @param int $entityId
		 * @return AjaxJson
		 */
		public function entityAction(string $entityCode, int $entityId, bool $pointEis = false): AjaxJson {

			$errCollection = (new ErrorCollection());

			Loader::includeModule('crm');

			$result = [];

			if($entityCode == 'deal'){

				$deal = DealTable::getByPrimary($entityId);

				if($deal->getSelectedRowscount() == 0){

					$errCollection->add([new Error('Не удалось обнаружить сделку с идентификатором "'.$entityId.'".', 'NOT_DEAL')]);

					return AjaxJson::createError($errCollection);

				}

				// получаем объект сделки
				$deal = $deal->fetchObject();

				if($deal->getContactId() == 0){

					$errCollection->add([new Error('К сделке не привязан контакт.', 'NOT_BIND_CONTACT')]);

					return AjaxJson::createError($errCollection);

				}

				$contactId = $deal->getContactId();

			} else if($entityCode == 'contact') {
				$contactId = $entityId;
			}

			$point = []; // информация по баллам

			// получаем баллы из системы
			$pointOrm = PointTable::getByPrimary($contactId);

			if($pointOrm->getSelectedRowsCount() > 0){

				$pointOrm = $pointOrm->fetchObject();

				$point = [

					'MR' => $pointOrm->getMr(),
					'MR_RUB' => $pointOrm->getMrRub(),
					'MR_ACCOUNT_ID' => $pointOrm->getMrAccountId(),
					'MR_RATE' => $pointOrm->getMrRate(),

					'IMPERIA' => $pointOrm->getImperia(),
					'IMPERIA_RUB' => $pointOrm->getImperiaRub(),
					'IMPERIA_ACCOUNT_ID' => $pointOrm->getImperiaAccountId(),
					'IMPERIA_RATE' => $pointOrm->getImperiaRate(),

				];

			}

			// получаем баллы из фронта
			if($pointEis){

				$point = [];

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

				// осуществляем запрос в EIS и получаем баланс аккаунта
				$pointEis = Eis::call('brs.main', 'GetClientData', [
					'clientId' => $contactPropertyKsId
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

				foreach($pointEis as $resultValue){
					if(array_key_exists('loyCode', $resultValue) && !empty($resultValue['loyCode'])){
						$point[str_replace(['Imperia_R'], ['IMPERIA'], $resultValue['loyCode'])] = [
							'POINT' => number_format($resultValue['balance'], 2, '.', ''),
							'RUB' => number_format($resultValue['price'], 2, '.', ''),
							'RATE' => number_format($resultValue['curs'], 4, '.', ''),
							'ACCOUNT_ID' => $resultValue['acctNum'],
						];
					}
				}

				// сохраняем баллы в системе
				if(count($point) > 0){

					$pointResponse = [
						'MR' => 0,
						'MR_RUB' => 0,
						'IMPERIA' => 0,
						'IMPERIA_RUB' => 0,
					];

					$pointOrm = PointTable::getByPrimary($contactId);

					if($pointOrm->getSelectedRowsCount() == 0){

						$pointOrm = PointTable::createObject();

						$pointOrm->setContactId($contactId);

					} else {

						$pointOrm = $pointOrm->fetchObject();

						$pointOrm->setDateModify((new \DateTime)->format('d.m.Y H:i:s'));

					}

					foreach($point as $pointCode => $balance){

						$pointResponse[$pointCode] = $balance['POINT'];
						$pointResponse[$pointCode.'_RUB'] = $balance['RUB'];
						$pointResponse[$pointCode.'_ACCOUNT_ID'] = $balance['ACCOUNT_ID'];
						$pointResponse[$pointCode.'_RATE'] = $balance['RATE'];

						$pointOrm->set($pointCode, $balance['POINT']);
						$pointOrm->set($pointCode.'_RUB', $balance['RUB']);
						$pointOrm->set($pointCode.'_ACCOUNT_ID', $balance['ACCOUNT_ID']);
						$pointOrm->set($pointCode.'_RATE', $balance['RATE']);
						
					}

					$pointOrm->save();

					$point = $pointResponse;

				}

			}

			return AjaxJson::createSuccess($point);

		}

	}
