<?php
	
	namespace Brs\ReceiptOfd;
	
	use Bitrix\Main\Loader;
	use Bitrix\Main\Type\DateTime;

	use Brs\Log\Model\Orm\ReceiptLogTable;
	use Brs\ReceiptOfd\Models\ReceiptTable;
	
	use Brs\ReceiptOfd\Ofd\Receipt as OfdReceipt;

	/**
	 * Обёртка для ORM чеков. Содержит техническую часть бизнес логики.
	 */
	class Receipt {
		
		private bool $isUpdate = false; // добавляем элемент или обновляем

		private int $id = 0; // идентификатор элемента
		private int $lastId = 0; // идентификатор последнего изменённого элемента или добавленного элемента

		private array $errors = []; // список ошибок
		private array $fields = []; // параметры чека
		private array $prevFields = []; // предыдущие параметры чека (до вносимых изменений через метод save)

		/**
		 * Принимает параметры полей элемента чека (как в ORM ReceiptTable) и формирует базовые свойства текущего класса.
		 * 
		 * @param array $fields изменяемые параметры элемента чека из ORM ReceiptTable
		 */
		public function __construct(array $fields = []){
			
			$this->fields = $fields;

			$this->isUpdate = array_key_exists('ID', $this->fields) && !empty($this->fields['ID']); // добавляем элемент или обновляем

			if($this->isUpdate){ // если необходимо изменить существующий чек

				$this->id = $this->fields['ID'];
				$this->prevFields = $this->info($this->id); // сохраняем текущие параметры чека

				unset($this->fields['ID']);

			}

		}

		/**
		 * Изменяет или добавляет элемент чека с учётом бизнес логики.
		 * 
		 * @param array $fields 
		 * @return object возвращает объект текущего класса
		 */
		public static function save(array $fields): object {

			$receipt = new self($fields);

			if(!$receipt->checkFields()){ // проверяем поля на ошибки

				$receipt->log(); // формируем лог по действию

				return $receipt;

			}

			if($receipt->isUpdate()){ // изменяем элемент в случае, если был передан идентификатор
				$receipt->__update();
			} else {

				$receipt->addSupplementingFields(); // дополняем поля данными

				$receipt->add();

			}

			$receipt->log(); // формируем лог по действию

			return $receipt;

		}

		/**
		 * Отправляет чек в офд (пытается его создать).
		 * 
		 * @param int $receiptId
		 * @param array $receipt
		 * @return object
		 * @throws \Exception
		 */
		public static function push(int $receiptId, array $receipt = []): object {

			if(count($receipt) == 0){
				$receipt = self::info($receiptId); // получаем чек в системе
			}

			if(!$receipt){
				throw new \Exception('Чек по идентификатору "'.$receiptId.'" не найден в системе.');
			}
			
			$ofd = new OfdReceipt;

			if(empty($receipt['RECEIPT_ID'])){ // если идентификатор чека в офд пустой, то создаём чек

				$createResponse = $ofd->create($receipt['REQUEST_RECEIPT_JSON']); // создаём чек в OFD и получаем его идентификатор

				if(!array_key_exists('ReceiptId', $createResponse) || empty($createResponse['ReceiptId'])){
					throw new \Exception('Не удалось создать чек в ОФД. В ответе от ofd.ru нет идентификатора чека (параметр "ReceiptId").');
				}

				sleep(2);

				$ofdReceiptId = $createResponse['ReceiptId'] ?? '';

			} else {
				$ofdReceiptId = $receipt['RECEIPT_ID'];
			}

			$infoResponse = $ofd->info($ofdReceiptId); // получаем данные чека в OFD
			
			$url = '';
			
			if(self::hasUrl($infoResponse)){
				$url = self::getUrl($infoResponse);
			}

			$fields = [
				'ID' => $receiptId,
				'RECEIPT' => serialize($infoResponse),
				'RECEIPT_ID' => $ofdReceiptId,
				'RECEIPT_URL' => $url,
				'RECEIPT_NUMBER' => $infoResponse[0]['Receipt']['cashboxInfoHolder']['FDN'] ?? '',
				'ATTEMPT' => $receipt['ATTEMPT']
			];
			
			if(!$fields['RECEIPT_ID'] || !$fields['RECEIPT_NUMBER']){
				$fields['STATUS'] = 'NEW';
				$fields['ATTEMPT'] += 1;
			} else {
				$fields['STATUS'] = 'SENDED';
				$fields['ATTEMPT'] = 0;
			}
			
			return self::save($fields);

		}

		/**
		 * Получает чек из OFD.
		 * 
		 * @param int $receiptId
		 * @param array $receipt
		 * @return object
		 * @throws \Exception
		 */
		public static function pull(int $receiptId, array $receipt = []): object {

			if(count($receipt) == 0){
				$receipt = self::info($receiptId); // получаем чек в системе
			}

			if(!$receipt){
				throw new \Exception('Чек по идентификатору "'.$receiptId.'" не найден в системе.');
			}

			$fields = [
				'ID' => $receiptId,
			];

			$ofd = new OfdReceipt;

			$url = $receipt['RECEIPT_URL'];

			if(empty($receipt['RECEIPT_URL'])){ // если ссылка на чек была ранее не заполнена, то формируем её заново

				$response = $ofd->info($receipt['RECEIPT_ID']); // ищем чек в OFD и получаем параметры

				if(self::hasUrl($response)){

					$fields['RECEIPT_URL'] = self::getUrl($response);
					$fields['RECEIPT_HTML'] = $ofd->getHtml($fields['RECEIPT_URL']);

				}

			} else {
				$fields['RECEIPT_HTML'] = $ofd->getHtml($url);
			}

			if(!empty($fields['RECEIPT_HTML'])){ // если чек удалось заполнить, то устанавливаем статус "Создан"

				$fields['STATUS'] = 'CREATED';

				$fields['ATTEMPT'] = 0;

			} else {
				$fields['ATTEMPT'] += 1;
			}

			return self::save($fields);

		}

		/**
		 * Отдаёт информацию по чеку.
		 * 
		 * @param int $id идентификатор чека
		 * @return array|null
		 */
		public static function info(int $id): ?array {

			$receipt = ReceiptTable::getById($id);

			if($receipt->getSelectedRowsCount() === 0){
				return null;
			}

			return $receipt->fetch();

		}

		/**
		 * Проверка заполненности полей.
		 * 
		 * @return bool
		 */
		public function checkFields(): bool {

			if(count($this->fields) == 0){ // если параметры не были переданы

				$this->addError('Переданы пустые параметры полей');

				return false;

			}

			if($this->isUpdate){
				return $this->checkFieldsUpdate(); // проверка на заполненность полей при изменении элемента
			} else {
				return $this->checkFieldsAdd(); // проверка на заполненность полей при добавлении элемента
			}

			return true;

		}

		/**
		 * Дополняем поля данными.
		 * 
		 * @return void
		 */
		private function addSupplementingFields(): void {

			if(!array_key_exists('PAYMENT_ID', $this->fields)){
				$this->fields['PAYMENT_ID'] = 0; 
			}

			if(!array_key_exists('DATE_CREATE', $this->fields)){
				$this->fields['DATE_CREATE'] = new DateTime; 
			}

			if(!array_key_exists('RECEIPT', $this->fields)){
				$this->fields['RECEIPT'] = 'a:0:{}'; 
			}

		}

		/**
		 * Проверяем поля при добавлении чека.
		 * 
		 * @return bool
		 */
		private function checkFieldsAdd(): bool {

			$requireFields = [
				'DEAL_ID'
			];

			foreach($requireFields as $requireField){
				if(!array_key_exists($requireField, $this->fields) && empty($this->fields[$requireField])){
					$this->addError('Параметр "'.$requireField.'" должен быть обязательно заполнен');
				}
			}

			if(!$this->isSuccess()){
				return false;
			}

			return true;

		}

		/**
		 * Проверяем поля при добавлении чека.
		 * 
		 * @return bool
		 */
		private function checkFieldsUpdate(): bool {

			$isExistCredit = ReceiptTable::getById($this->getId())->getSelectedRowsCount() > 0; // есть ли такой чек в системе

			if(!$isExistCredit){

				$this->addError('Чек по идентификатору "'.$this->getId().'" не обнаружен.');

				return false;

			}

			return true;

		}

		/**
		 * Обновляется ли элемент чека.
		 * 
		 * @return bool
		 */
		public function isUpdate(): bool {
			return $this->isUpdate;
		}

		/**
		 * Создаёт лог в системе.
		 * 
		 * @return void
		 */
		public function log(): void {

			Loader::includeModule('brs.log');

			if($this->lastId == 0){
				return;
			}
			
			$log = ReceiptLogTable::createObject(); // создаём запись в логах
			
			if(!empty($this->fields['DEAL_ID'])){
				$log->setDealId($this->fields['DEAL_ID']);
			}

			$log->setReceiptId($this->lastId);

			if($this->isSuccess()){
				return; // $log->setStatusCode('SUCCESS');
			} else {

				$log->setStatusCode('ERROR');

				$log->setErrorMessage($this->getErrorMessages()); // текст ошибок

			}
			
			if($this->isUpdate()){
				$log->setEventCode('UPDATE');
			} else {
				$log->setEventCode('ADD');
			}
			
			$this->prevFields['DATE_CREATE'] = $this->prevFields['DATE_CREATE']->toString();
			$this->prevFields['DATE_MODIFY'] = $this->prevFields['DATE_MODIFY']->toString();

			$log->setParamsBefore(\json_encode($this->prevFields)); // параметры элемента до изменения
			$log->setParamsAfter(\json_encode($this->fields)); // параметры элемента после изменения

			$log->save();

		}

		/**
		 * Успешна ли была последняя транзакция.
		 * 
		 * @return bool
		 */
		public function isSuccess(): bool {
			return count($this->errors) === 0;
		}

		/**
		 * Добавляет ошибку.
		 * 
		 * @param string $message
		 * @return void
		 */
		private function addError(string $message): void {
			$this->errors[] = $message;
		}

		/**
		 * Выводит список ошибок в формате массива.
		 * 
		 * @return array
		 */
		public function getErrors(): array {
			return $this->errors;
		}

		/**
		 * Выводит список ошибок в формате строки.
		 * 
		 * @return string
		 */
		public function getErrorMessages(): string {

			if(count($this->errors) === 0){
				return '';
			}

			return implode("<br>\r\n", $this->errors);

		}

		/**
		 * Отдаёт идентификатор элемента.
		 * 
		 * @return int
		 */
		public function getId(): int {
			return $this->id;
		}

		/**
		 * Отдаёт последний идентификатор элемента.
		 * 
		 * @return int
		 */
		public function getLastId(): int {
			return $this->lastId;
		}

		/**
		 * Добавляет элемент в справочник "Чеки в сделке".
		 * 
		 * @param array $fields
		 * @return object
		 */
		public function add(): object {

			$result = ReceiptTable::add($this->fields);

			if(!$result->isSuccess()){

				foreach($result->getErrorMessages() as $error){
					$this->addError($error);
				}

				return $this;

			}

			$this->id = $result->getId(); // идентификатор чека

			return $this;

		}

		/**
		 * Изменяет чек.
		 * 
		 * @param int $id
		 * @param array $fields
		 * @return object
		 */
		public static function update(int $id, array $fields): object {

			$fields['ID'] = $id;

			$receipt = new self($fields);

			if(!$receipt->checkFields()){ // проверяем поля на ошибки

				$receipt->log(); // формируем лог по действию

				return $receipt;

			}

			$receipt->__update();

			$receipt->log(); // формируем лог по действию

			return $receipt;

		}

		/**
		 * Изменяет элемент в справочнике "Чеки".
		 * 
		 * @return object
		 */
		public function __update(): object {

			$result = ReceiptTable::update($this->id, $this->fields);

			if(!$result->isSuccess()){

				$this->addError($result->getErrorMessages());

				return $this;

			}

			$this->id = current($result->getPrimary());
			$this->lastId = $this->id;

			return $this;

		}

		/**
		 * Нужно ли получить ссылку на чек в ОФД?
		 * 
		 * @param array $response ответ из OFD
		 * @return bool
		 */
		private function hasUrl(array $response): bool {
			return $response[0]['Receipt']['cashboxInfoHolder']['RNM'] && $response[0]['Receipt']['cashboxInfoHolder']['FN'] && $response[0]['Receipt']['cashboxInfoHolder']['FDN'] && $response[0]['Receipt']['cashboxInfoHolder']['FPD'];
		}

		/**
		 * Формируем ссылку на чек
		 * 
		 * @param array $response ответ из OFD
		 * @return string
		 */
		private function getUrl(array $response): string {

			$prefix = \COption::GetOptionString('brs.receiptofd', 'PREFIX_HTML_URL');
			$htmlUrl = \COption::GetOptionString('brs.receiptofd', 'HTML_URL');

			return $htmlUrl.implode('/', [

				$prefix,

				$response[0]['Receipt']['cashboxInfoHolder']['RNM'],
				$response[0]['Receipt']['cashboxInfoHolder']['FN'],
				$response[0]['Receipt']['cashboxInfoHolder']['FDN'],
				$response[0]['Receipt']['cashboxInfoHolder']['FPD']

			]);

		}

	} 
