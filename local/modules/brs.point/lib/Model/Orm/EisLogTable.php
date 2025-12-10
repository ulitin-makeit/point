<?php

	namespace Brs\Point\Model\Orm;

	use Bitrix\Main\ORM\Fields;
	use Bitrix\Main\Type\DateTime;

	use Bitrix\Main\ORM\Data\DataManager;

	class EisLogTable extends DataManager {
		
		// поля ORM (соответствие коду)
		public static array $codeToProps = [

			'ID' => 'ID',

			'ENTITY_CODE' => 'Сущность',
			'METHOD' => 'Метод',
			'STATUS' => 'Статус',

			'REQUEST_FROM' => 'Откуда запрос',
			'REQUEST_PARAMS' => [
				'NAME' => 'Параметры запроса',
				'TYPE' => 'JSON'
			],

			'RESPONSE' => [
				'NAME' => 'Ответ',
				'TYPE' => 'JSON'
			],

			'DATE_RUN' => 'Дата запроса',

		];

		public static function getTableName(): string {
			return 'brs_point_eis_log';
		}

		public static function getMap(): array {
			return [

				new Fields\IntegerField('ID', [ // идентификатор
					'primary' => true,
					'autocomplete' => true,
					'column_name' => 'ID'
				]),

				new Fields\StringField('ENTITY_CODE'), // сущность

				new Fields\StringField('METHOD'), // вызываемый метод
				new Fields\StringField('STATUS'), // статус
				
				new Fields\StringField('REQUEST_FROM'), // откуда запрос
				new Fields\StringField('REQUEST_PARAMS'), // параметры запроса

				new Fields\StringField('RESPONSE'), // ответ

				new Fields\DateTimeField('DATE_RUN', array( // дата и время отправки запроса
					'default_value' => new DateTime()
				)),

			];
		}

	}
