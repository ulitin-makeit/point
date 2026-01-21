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

		// Логируем начало работы агента
		if (function_exists('AddMessage2Log')) {
			AddMessage2Log(
				'AccountingEntry Agent START: dealId=' . $dealId,
				'brs.point.AccountingEntry',
				0,
				true
			);
		}

		if ($dealId <= 0) {
			if (function_exists('AddMessage2Log')) {
				AddMessage2Log(
					'AccountingEntry Agent: Invalid dealId=' . $dealId,
					'brs.point.AccountingEntry',
					0,
					true
				);
			}
			return __METHOD__.'();';
		}

		try {
			$service = new AccountingEntryService();
			$service->createRealizationEntrance($dealId);
			
			if (function_exists('AddMessage2Log')) {
				AddMessage2Log(
					'AccountingEntry Agent END: dealId=' . $dealId . ' - Success',
					'brs.point.AccountingEntry',
					0,
					true
				);
			}
		} catch (\Exception $e) {
			if (function_exists('AddMessage2Log')) {
				AddMessage2Log(
					'AccountingEntry Agent ERROR: dealId=' . $dealId . ' - ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString(),
					'brs.point.AccountingEntry',
					0,
					true
				);
			}
			throw $e;
		}
		
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
