<?php

	namespace Brs\Point\Agent;

	use Brs\Point\Service\AccountingEntryService;

	/**
	 * Агент работает с бух. проводками по баллам.
	 */
	class AccountingEntry {

		/**
		 * Создаём проводки реализации и поступления при оплате баллами.
		 * 
		 * @param int $dealId идентификатор сделки
		 * @return string
		 */
		static function createRealizationEntrance(int $dealId = 0) : string {

			if ($dealId <= 0) {
				return __METHOD__.'();';
			}

			$service = new AccountingEntryService();
			$service->createRealizationEntrance($dealId);
			
			return __METHOD__.'();';

		}


		/**
		 * Получаем список идентификаторов из сделок.
		 * 
		 * @param array $dealList
		 * @return array
		 */
		static function getDealIdListOfDealList(array $dealList): array {
			return array_column($dealList, 'ID');
		}

	}
