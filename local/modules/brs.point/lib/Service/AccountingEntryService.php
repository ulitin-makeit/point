<?php

namespace Brs\Point\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Diag\Debug;
use Brs\IncomingPaymentEcomm\Models\EO_PaymentTransaction;
use Brs\Main\Crm\Deal\Price;
use Brs\Exchange1C\Models\AccountingEntryTable;
use Brs\Exchange1c\AccountingEntry\ServiceActBuyer;
use Brs\Exchange1c\AccountingEntry\ServiceActSupplier;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\IncomingPaymentEcomm\Models\PaymentTransactionTable;

/**
 * Сервис для работы с бухгалтерскими проводками по баллам.
 * Отвечает за бизнес-логику обработки сделок и принятие решений о создании проводок.
 */
class AccountingEntryService
{
	/**
	 * Типы бухгалтерских проводок
	 */
	private const ENTITY_SERVICE_ACT_SUPPLIER = 'SERVICE_ACT_SUPPLIER';
	private const ENTITY_SERVICE_ACT_BUYER = 'SERVICE_ACT_BUYER';

	/**
	 * Схемы работы поставщика-агента
	 */
	private const SUPPLIER_AGENT_SCHEMES = [
		FinancialCardTable::SCHEME_SR_SUPPLIER_AGENT,
		FinancialCardTable::SCHEME_LR_SUPPLIER_AGENT,
		FinancialCardTable::SCHEME_RS_TLS_SERVICE_FEE
	];

	public function __construct()
	{
		$modulesLoaded = [];
		$modulesLoaded['brs.incomingpaymentecomm'] = Loader::includeModule('brs.incomingpaymentecomm');
		$modulesLoaded['brs.financialcard'] = Loader::includeModule('brs.financialcard');
		$modulesLoaded['brs.exchange1c'] = Loader::includeModule('brs.exchange1c');
		
		$this->logDebug('AccountingEntryService::__construct', [
			'modules_loaded' => $modulesLoaded,
			'is_agent' => $this->isAgentContext(),
			'context' => $this->getContextInfo()
		]);
	}

	/**
	 * Создаёт проводки реализации и поступления при оплате баллами.
	 *
	 * @param int $dealId идентификатор сделки
	 * @return void
	 */
	public function createRealizationEntrance(int $dealId): void
	{
		$this->logDebug('AccountingEntryService::createRealizationEntrance START', [
			'deal_id' => $dealId,
			'is_agent' => $this->isAgentContext(),
			'context' => $this->getContextInfo()
		]);

		// Получаем последний платёж баллами по сделке
		// Если платежа нет, обработка прекращается
		$payment = $this->getLastPointPayment($dealId);
		if ($payment === null) {
			$this->logDebug('AccountingEntryService::createRealizationEntrance - Payment not found', [
				'deal_id' => $dealId
			]);
			return;
		}

		$this->logDebug('AccountingEntryService::createRealizationEntrance - Payment found', [
			'deal_id' => $dealId,
			'payment_id' => $payment->getId(),
			'payment_by_point' => $payment->getPaymentByPoint()
		]);

		// Проверяем, что по сделке нет задолженности
		// Проводки создаются только для полностью оплаченных сделок
		$amountDebt = Price::getAmountDebtRub($dealId);
		$this->logDebug('AccountingEntryService::createRealizationEntrance - Debt check', [
			'deal_id' => $dealId,
			'amount_debt' => $amountDebt
		]);
		
		if ($amountDebt > 0) {
			$this->logDebug('AccountingEntryService::createRealizationEntrance - Debt exists, skipping', [
				'deal_id' => $dealId,
				'amount_debt' => $amountDebt
			]);
			return;
		}

		// Получаем финансовую карту по сделке
		// Финансовая карта необходима для определения схемы работы и типа проводок
		$financialCard = $this->getFinancialCard($dealId);
		if ($financialCard === null) {
			$this->logDebug('AccountingEntryService::createRealizationEntrance - Financial card not found', [
				'deal_id' => $dealId
			]);
			return;
		}

		$this->logDebug('AccountingEntryService::createRealizationEntrance - Financial card found', [
			'deal_id' => $dealId,
			'financial_card_id' => $financialCard['ID'],
			'scheme_work' => $financialCard['SCHEME_WORK'] ?? null
		]);

		// Создаём бухгалтерские проводки на основе схемы работы финансовой карты
		$this->createAccountingEntries($dealId, $financialCard);
		
		$this->logDebug('AccountingEntryService::createRealizationEntrance END', [
			'deal_id' => $dealId
		]);
	}

	/**
	 * Получает последний платёж баллами по сделке.
	 *
	 * Ищет последний успешный платёж, выполненный баллами лояльности.
	 * Используется для создания бухгалтерских проводок.
	 *
	 * @param int $dealId идентификатор сделки
	 */
	private function getLastPointPayment(int $dealId): ?EO_PaymentTransaction
	{
		$this->logDebug('AccountingEntryService::getLastPointPayment START', [
			'deal_id' => $dealId
		]);

		try {
			// Получаем последний платёж баллами по сделке
			// Сортируем по ID в убывающем порядке, чтобы получить самый последний
			$paymentResult = PaymentTransactionTable::getList([
				'select' => [
					'ID',
					'DEAL_ID',
					'PAYMENT_BY_POINT'
				],
				'filter' => [
					'DEAL_ID' => $dealId,
					'PAYMENT_BY_POINT' => true // Только платежи баллами
				],
				'order' => ['ID' => 'DESC'],
				'limit' => 1
			]);

			$count = $paymentResult->getSelectedRowsCount();
			$this->logDebug('AccountingEntryService::getLastPointPayment - Query result', [
				'deal_id' => $dealId,
				'found_count' => $count
			]);

			// Если платежей не найдено, возвращаем null
			if ($count === 0) {
				return null;
			}

			$payment = $paymentResult->fetchObject();
			$this->logDebug('AccountingEntryService::getLastPointPayment - Payment found', [
				'deal_id' => $dealId,
				'payment_id' => $payment ? $payment->getId() : null
			]);

			return $payment;
		} catch (\Exception $e) {
			$this->logDebug('AccountingEntryService::getLastPointPayment - Exception', [
				'deal_id' => $dealId,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}

	/**
	 * Получает финансовую карту по сделке.
	 *
	 * Финансовая карта содержит информацию о схеме работы (SCHEME_WORK),
	 * которая определяет, какие типы проводок необходимо создавать.
	 *
	 * @param int $dealId идентификатор сделки
	 * @return array|null данные финансовой карты (ID, SCHEME_WORK) или null, если карта не найдена
	 */
	private function getFinancialCard(int $dealId): ?array
	{
		$this->logDebug('AccountingEntryService::getFinancialCard START', [
			'deal_id' => $dealId
		]);

		try {
			// Получаем финансовую карту по сделке
			// Нас интересует только схема работы для определения типа проводок
			$financialCardResult = FinancialCardTable::getList([
				'select' => [
					'ID',
					'SCHEME_WORK' // Схема работы определяет тип проводок
				],
				'filter' => [
					'DEAL_ID' => $dealId
				],
				'limit' => 1
			]);

			$count = $financialCardResult->getSelectedRowsCount();
			$this->logDebug('AccountingEntryService::getFinancialCard - Query result', [
				'deal_id' => $dealId,
				'found_count' => $count
			]);

			// Если финансовая карта не найдена, возвращаем null
			if ($count === 0) {
				return null;
			}

			$financialCard = $financialCardResult->fetch();
			$this->logDebug('AccountingEntryService::getFinancialCard - Card found', [
				'deal_id' => $dealId,
				'financial_card_id' => $financialCard['ID'] ?? null,
				'scheme_work' => $financialCard['SCHEME_WORK'] ?? null
			]);

			return $financialCard;
		} catch (\Exception $e) {
			$this->logDebug('AccountingEntryService::getFinancialCard - Exception', [
				'deal_id' => $dealId,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}

	/**
	 * Создаёт бухгалтерские проводки для сделки.
	 *
	 * Логика создания проводок:
	 * - Проводка "Акт поставщика" создаётся только если схема работы НЕ является схемой поставщика-агента
	 *   и проводка ещё не была создана ранее
	 * - Проводка "Акт покупателя" создаётся всегда, если она ещё не была создана ранее
	 *
	 * @param int $dealId идентификатор сделки
	 * @param array $financialCard финансовая карта с информацией о схеме работы
	 * @return void
	 */
	private function createAccountingEntries(int $dealId, array $financialCard): void
	{
		$this->logDebug('AccountingEntryService::createAccountingEntries START', [
			'deal_id' => $dealId,
			'financial_card_id' => $financialCard['ID'] ?? null,
			'scheme_work' => $financialCard['SCHEME_WORK'] ?? null,
			'supplier_agent_schemes' => self::SUPPLIER_AGENT_SCHEMES
		]);

		// Проверяем, является ли схема работы схемой поставщика-агента
		// Для таких схем проводка "Акт поставщика" не создаётся
		$isSupplierAgentScheme = in_array($financialCard['SCHEME_WORK'], self::SUPPLIER_AGENT_SCHEMES, true);
		
		$this->logDebug('AccountingEntryService::createAccountingEntries - Scheme check', [
			'deal_id' => $dealId,
			'scheme_work' => $financialCard['SCHEME_WORK'] ?? null,
			'is_supplier_agent_scheme' => $isSupplierAgentScheme
		]);

		// Создаём проводку "Акт поставщика" только если:
		// 1. Схема работы НЕ является схемой поставщика-агента
		// 2. Проводка ещё не была создана ранее
		if (!$isSupplierAgentScheme) {
			$supplierExists = $this->isAccountingEntryExists($dealId, self::ENTITY_SERVICE_ACT_SUPPLIER);
			$this->logDebug('AccountingEntryService::createAccountingEntries - Supplier entry check', [
				'deal_id' => $dealId,
				'entity' => self::ENTITY_SERVICE_ACT_SUPPLIER,
				'exists' => $supplierExists
			]);
			
			if (!$supplierExists) {
				$this->logDebug('AccountingEntryService::createAccountingEntries - Creating supplier entry', [
					'deal_id' => $dealId
				]);
				try {
					ServiceActSupplier::handler(['dealId' => $dealId], []);
					$this->logDebug('AccountingEntryService::createAccountingEntries - Supplier entry created', [
						'deal_id' => $dealId
					]);
				} catch (\Exception $e) {
					$this->logDebug('AccountingEntryService::createAccountingEntries - Supplier entry error', [
						'deal_id' => $dealId,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString()
					]);
					throw $e;
				}
			} else {
				$this->logDebug('AccountingEntryService::createAccountingEntries - Supplier entry already exists', [
					'deal_id' => $dealId
				]);
			}
		} else {
			$this->logDebug('AccountingEntryService::createAccountingEntries - Skipping supplier entry (agent scheme)', [
				'deal_id' => $dealId
			]);
		}

		// Создаём проводку "Акт покупателя", если она ещё не была создана
		// Эта проводка создаётся всегда, независимо от схемы работы
		$buyerExists = $this->isAccountingEntryExists($dealId, self::ENTITY_SERVICE_ACT_BUYER);
		$this->logDebug('AccountingEntryService::createAccountingEntries - Buyer entry check', [
			'deal_id' => $dealId,
			'entity' => self::ENTITY_SERVICE_ACT_BUYER,
			'exists' => $buyerExists
		]);
		
		if (!$buyerExists) {
			$this->logDebug('AccountingEntryService::createAccountingEntries - Creating buyer entry', [
				'deal_id' => $dealId
			]);
			try {
				ServiceActBuyer::handler(['dealId' => $dealId], []);
				$this->logDebug('AccountingEntryService::createAccountingEntries - Buyer entry created', [
					'deal_id' => $dealId
				]);
			} catch (\Exception $e) {
				$this->logDebug('AccountingEntryService::createAccountingEntries - Buyer entry error', [
					'deal_id' => $dealId,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				]);
				throw $e;
			}
		} else {
			$this->logDebug('AccountingEntryService::createAccountingEntries - Buyer entry already exists', [
				'deal_id' => $dealId
			]);
		}
		
		$this->logDebug('AccountingEntryService::createAccountingEntries END', [
			'deal_id' => $dealId
		]);
	}

	/**
	 * Проверяет существование бухгалтерской проводки.
	 *
	 * Используется для предотвращения дублирования проводок.
	 * Проверяет наличие проводки определённого типа для конкретной сделки.
	 *
	 * @param int $dealId идентификатор сделки
	 * @param string $entity тип проводки (ENTITY_SERVICE_ACT_SUPPLIER или ENTITY_SERVICE_ACT_BUYER)
	 * @return bool true если проводка существует, false если нет
	 */
	private function isAccountingEntryExists(int $dealId, string $entity): bool
	{
		$this->logDebug('AccountingEntryService::isAccountingEntryExists START', [
			'deal_id' => $dealId,
			'entity' => $entity
		]);

		try {
			// Проверяем наличие проводки по типу и идентификатору сделки
			$result = AccountingEntryTable::getList([
				'select' => ['ID'],
				'filter' => [
					'ENTITY' => $entity,
					'DEAL_ID' => $dealId
				],
				'limit' => 1
			]);
			
			$exists = $result->getSelectedRowsCount() > 0;
			
			$this->logDebug('AccountingEntryService::isAccountingEntryExists - Result', [
				'deal_id' => $dealId,
				'entity' => $entity,
				'exists' => $exists
			]);
			
			return $exists;
		} catch (\Exception $e) {
			$this->logDebug('AccountingEntryService::isAccountingEntryExists - Exception', [
				'deal_id' => $dealId,
				'entity' => $entity,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}

	/**
	 * Определяет, запущен ли код в контексте агента Битрикса.
	 *
	 * @return bool
	 */
	private function isAgentContext(): bool
	{
		// Проверяем наличие переменных, характерных для агентов
		return (defined('BX_CRONTAB') && BX_CRONTAB === true) 
			|| (php_sapi_name() === 'cli')
			|| (!isset($_SERVER['REQUEST_METHOD']));
	}

	/**
	 * Получает информацию о контексте выполнения.
	 *
	 * @return array
	 */
	private function getContextInfo(): array
	{
		return [
			'sapi_name' => php_sapi_name(),
			'is_cli' => php_sapi_name() === 'cli',
			'is_crontab' => defined('BX_CRONTAB') ? BX_CRONTAB : false,
			'has_request_method' => isset($_SERVER['REQUEST_METHOD']),
			'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
			'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
			'argv' => $_SERVER['argv'] ?? null
		];
	}

	/**
	 * Логирует отладочную информацию.
	 *
	 * @param string $message сообщение
	 * @param array $data дополнительные данные
	 * @return void
	 */
	private function logDebug(string $message, array $data = []): void
	{
		$logData = [
			'message' => $message,
			'data' => $data,
			'timestamp' => date('Y-m-d H:i:s'),
			'memory_usage' => memory_get_usage(true),
			'memory_peak' => memory_get_peak_usage(true)
		];

		// Используем стандартное логирование Битрикса
		if (function_exists('AddMessage2Log')) {
			AddMessage2Log(
				print_r($logData, true),
				'brs.point.AccountingEntryService',
				0,
				true
			);
		}

		// Дополнительно логируем через Debug, если доступен
		if (class_exists('\Bitrix\Main\Diag\Debug')) {
			Debug::writeToFile($logData, $message, '/local/logs/accounting_entry_service.log');
		}
	}
}
