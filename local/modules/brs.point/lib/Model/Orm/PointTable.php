<?php

	namespace Brs\Point\Model\Orm;

	use Bitrix\Main\ORM\Fields;
	use Bitrix\Main\Type\DateTime;

	use Bitrix\Main\ORM\Data\DataManager;

	class PointTable extends DataManager {
		
		public static function getTableName(): string {
			return 'brs_contact_point_card';
		}

		public static function getMap(): array {
			return [

				new Fields\IntegerField('ID', [
					'autocomplete' => true,
				]),

				new Fields\IntegerField('CONTACT_ID', [ // ID контакта
					'primary' => true,
				]),

				new Fields\FloatField('MR'), // к-во баллов MR
				new Fields\FloatField('MR_RUB'), // к-во баллов MR в рублях
				new Fields\IntegerField('MR_ACCOUNT_ID'), // идентификатор бонусного счёта MR
				new Fields\FloatField('MR_RATE'), // курс

				new Fields\FloatField('IMPERIA'), // к-во баллов IMPERIA
				new Fields\FloatField('IMPERIA_RUB'), // к-во баллов IMPERIA в рублях
				new Fields\IntegerField('IMPERIA_ACCOUNT_ID'), // идентификатор бонусного счёта IMPERIA
				new Fields\FloatField('IMPERIA_RATE'), // курс

				new Fields\DateTimeField('DATE_MODIFY', [
					'title' => 'Дата обновления',
					'default_value' => new DateTime()
				]),
				new Fields\DateTimeField('DATE_CREATED', [
					'title' => 'Дата добавления',
					'default_value' => new DateTime()
				]),

			];
		}

	}
