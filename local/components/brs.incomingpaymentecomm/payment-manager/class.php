<?php

use Bitrix\Crm\ContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Brs\CorporateClients\Models\Orm\CorporateClientsTable;
use Brs\FinancialCard\Repository\FinancialCard;
use Brs\Services\DealDate;
use Brs\Main\Model\Orm\Crm\Contact\ContactPropertyTable;
use Brs\FinancialCard\Credit;

class DealIncomePaymentManager extends CBitrixComponent
{
	private function getClientEmailList(): array
	{
		Loader::includeModule('crm');
		Loader::includeModule('brs.corporateclients');

		$emailList = [];

		$deal = CAllCrmDeal::GetByID($this->arParams['DEAL_ID']);
		
		$clientId = $deal['CONTACT_ID'];

		$this->arResult['CONTACT_ID'] = $clientId;

		$dbEmails = CCrmFieldMulti::GetList(
			[],
			[
				'ENTITY_ID' => 'CONTACT',
				'ELEMENT_ID' => $clientId,
				'TYPE_ID' => 'EMAIL',
			]
		);

		while ($email = $dbEmails->Fetch()) {
			$emailList[] = $email['VALUE'];
		}

		return $emailList;
	}

	private function getDealBeginDate(): ?\Bitrix\Main\Type\Date
	{
		$deal = DealTable::getlist(['filter'=>['ID' => $this->arParams['DEAL_ID']], 'select'=>['*', 'UF_*'], 'limit'=>1])->fetch();
		$dealDate = (new DealDate())->execute($deal);
		return $dealDate->getStartFieldValue();
	}

	/**
	 * Получаем идентификатор клиента кс из свойств контакта.
	 *
	 * @return array
	 */
	private function getClientOfContact(): array
	{

		return ContactPropertyTable::getByPrimary($this->arResult['CONTACT_ID'])->Fetch();
	}

	/**
	 * Получает компанию, если клиент является корпоративным
	 * @return ?array
	 */
	private function getCorpById(int $clientId = 0): ?array
	{
		$dbCorp = CorporateClientsTable::getById($clientId);

		if ($dbCorp->getSelectedRowsCount()) {
			return $dbCorp->fetch();
		} else {
			return null;
		}
	}

	/**
	 * @param $CONTACT_ID
	 * @return mixed
	 */
	public function getClientFullName($CONTACT_ID)
	{
		$db = ContactTable::getByPrimary($CONTACT_ID, ['select' => ['FULL_NAME']]);
		$client = $db->fetch();
		return $client['FULL_NAME'];
	}

	/**
	 * Получает курсы баллов для контакта
	 * @return array|null
	 */
	private function getPointRates(): ?array
	{
		if (!$this->arResult['CONTACT_ID']) {
			return null;
		}
		
		if (!Loader::includeModule('brs.point')) {
			return null;
		}
		
		$point = \Brs\Point\Model\Orm\PointTable::getByPrimary($this->arResult['CONTACT_ID']);
		
		if ($point->getSelectedRowsCount() === 0) {
			return null;
		}
		
		$pointData = $point->fetchObject();
		
		return [
			'MR_RATE' => $pointData->getMrRate(),
			'MR_ACCOUNT_ID' => $pointData->getMrAccountId(),
			'IMPERIA_RATE' => $pointData->getImperiaRate(),
			'IMPERIA_ACCOUNT_ID' => $pointData->getImperiaAccountId(),
			'MR' => $pointData->getMr(),
			'MR_RUB' => $pointData->getMrRub(),
			'IMPERIA' => $pointData->getImperia(),
			'IMPERIA_RUB' => $pointData->getImperiaRub(),
		];
	}

	public function executeComponent()
	{

		Loader::includeModule('brs.financialcard');

		$this->arResult['CONTACT_ID'] = 0;
		$this->arResult['DEAL_ID'] = $this->arParams['DEAL_ID'];

		$this->arResult['EMAIL_LIST'] = $this->getClientEmailList();
		$this->arResult['CLIENT_PAYMENT'] = FinancialCard::getDealPaymentType($this->arParams['DEAL_ID']);
		$this->arResult['DEAL_BEGIN_DATE'] = $this->getDealBeginDate();

		$client = $this->getClientOfContact();

		$this->arResult['CLIENT_ID'] = $client[\Brs\Entities\Contact::KS_ID];
		$this->arResult['CORP'] = $this->getCorpById((int) $client[\Brs\Entities\Contact::MOM_PLACE_WORK]);

		$this->arResult['CLIENT_FULL_NAME'] = $this->getClientFullName($client['CONTACT_ID']);

		$this->arResult['IS_CREDIT'] = Credit\Repository::isExistActive($this->arParams['DEAL_ID']); // есть ли активный кредит в сделке

		// Получаем курсы баллов для контакта
		$this->arResult['POINT_RATES'] = $this->getPointRates();

		global $GLOBALS;

		$groupsThatCan = [AGENTS_USER_GROUP_ID, MAIN_ADMIN_USER_GROUP_ID, FINANCE_USER_GROUP_ID, ADMIN_USER_GROUP_ID, TEAM_LEADERS_GROUP_ID];
		$groupList = UserTable::getUserGroupIds($GLOBALS['USER']->GetID());
		$accessButton = false;

		foreach($groupList as $groupId){
			if(in_array($groupId, $groupsThatCan)) {
				$accessButton = true;
			}
		}

		$this->arResult['IS_ACCESS_BUTTON_ALL'] = $accessButton;

		$this->includeComponentTemplate();

	}
}