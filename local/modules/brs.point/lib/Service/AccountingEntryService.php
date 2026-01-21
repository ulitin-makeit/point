<?php

namespace Brs\Point\Service;

use Bitrix\Main\Loader;
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
		Loader::includeModule('brs.incomingpaymentecomm');
		Loader::includeModule('brs.financialcard');
		Loader::includeModule('brs.exchange1c');
	}

	/**
	 * Создаёт проводки реализации и поступления при оплате баллами.
	 *
	 * @param int $dealId идентификатор сделки
	 * @return void
	 */
	public function createRealizationEntrance(int $dealId): void
	{
		// Получаем последний платёж баллами по сделке
		// Если платежа нет, обработка прекращается
		$payment = $this->getLastPointPayment($dealId);
		if ($payment === null) {
			return;
		}

		// Проверяем, что по сделке нет задолженности
		// Проводки создаются только для полностью оплаченных сделок
		$amountDebt = Price::getAmountDebtRub($dealId);
		if ($amountDebt > 0) {
			return;
		}

		// Получаем финансовую карту по сделке
		// Финансовая карта необходима для определения схемы работы и типа проводок
		$financialCard = $this->getFinancialCard($dealId);
		if ($financialCard === null) {
			return;
		}

		// Создаём бухгалтерские проводки на основе схемы работы финансовой карты
		$this->createAccountingEntries($dealId, $financialCard);
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

		// Если платежей не найдено, возвращаем null
		if ($paymentResult->getSelectedRowsCount() === 0) {
			return null;
		}

		return $paymentResult->fetchObject();
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

		// Если финансовая карта не найдена, возвращаем null
		if ($financialCardResult->getSelectedRowsCount() === 0) {
			return null;
		}

		return $financialCardResult->fetch();
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
		// Проверяем, является ли схема работы схемой поставщика-агента
		// Для таких схем проводка "Акт поставщика" не создаётся
		$isSupplierAgentScheme = in_array($financialCard['SCHEME_WORK'], self::SUPPLIER_AGENT_SCHEMES, true);

		// Создаём проводку "Акт поставщика" только если:
		// 1. Схема работы НЕ является схемой поставщика-агента
		// 2. Проводка ещё не была создана ранее
		if (!$isSupplierAgentScheme) {
			if (!$this->isAccountingEntryExists($dealId, self::ENTITY_SERVICE_ACT_SUPPLIER)) {
				ServiceActSupplier::handler(['dealId' => $dealId], []);
			}
		}

		// Создаём проводку "Акт покупателя", если она ещё не была создана
		// Эта проводка создаётся всегда, независимо от схемы работы
		if (!$this->isAccountingEntryExists($dealId, self::ENTITY_SERVICE_ACT_BUYER)) {
			ServiceActBuyer::handler(['dealId' => $dealId], []);
		}
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
		// Проверяем наличие проводки по типу и идентификатору сделки
		return AccountingEntryTable::getList([
				'select' => ['ID'],
				'filter' => [
					'ENTITY' => $entity,
					'DEAL_ID' => $dealId
				],
				'limit' => 1
			])->getSelectedRowsCount() > 0;
	}
}
