/**
 * Класс получает бонусные баллы и выводит его в сущности (контакт, сделка, финансовая краточка).
 */
class BrsPoint {
	
	#entityCode = 0; // код сущности
	#entityId = 0; // идентификатор сущности
	#pointEis = false;
	#entityClass = false; // класс или идентификатор для вставки формы
	#settings = false; // массив дополнительных параметров
	
	/**
	 * Получаем актуальный баланс бонусного счёта и выводим его в DOM узел.
	 */
	constructor(entityCode, entityId, entityClass, settings){
		
		this.#entityCode = entityCode;
		this.#entityId = entityId;
		this.#entityClass = (typeof entityClass === 'undefined') ? false : entityClass;
		this.#settings = (typeof entityClass === 'undefined') ? false : settings;
		
		this.#pointEis = false;
		
		this.renderSection(); // формируем DOM формы "Информация по баллам"
		
		this.getCountPoint(); // получаем актуальный баланс бонусного счёта
		
		this.#pointEis = true;
		
		$('.entityPointViewCountPoint').on('click', this.getCountPoint.bind(this));
		
	}
	
	/**
	 * Метод получает актуальный баланс бонусного счёта.
	 */
	getCountPoint(event){
		
		if(typeof event != 'undefined'){
			event.preventDefault();
		}
		
		$('.entityPointViewCountPoint').attr('disabled', 'disabled');
		
		// отправляем запрос в контроллер получения баллов модуля "brs.point"
		BX.ajax.runAction('brs:point.api.PointController.entity', {
			data: {
				entityCode: this.#entityCode,
				entityId: this.#entityId,
				pointEis: this.#pointEis
			}
		}).then(function (response) {
		
			$('.entityPointViewCountPoint').removeAttr('disabled', 'disabled');
			
			$('.crm_entity_point_error_block').hide(0);
		
			if(response.data.length === 0){
				return;
			}
			
			this.eventSuccessPointAction(response);
			
			$('.crm_entity_field_point_mr').text(response.data.MR);
			$('.crm_entity_field_point_mr_rub').text(response.data.MR_RUB);
			
			$('.crm_entity_field_point_imperia').text(response.data.IMPERIA);
			$('.crm_entity_field_point_imperia_rub').text(response.data.IMPERIA_RUB);
			
		}.bind(this), (response) => {
		
			$('.entityPointViewCountPoint').removeAttr('disabled', 'disabled');
			
			$('.crm_entity_point_error_block').show(0);
			
			$('.crm_entity_point_error_block_message').text(response.errors[0].message);
			
		});
		
		return false;
		
	}
	
	/**
	 * Событие успешного получения баллов
	 */
	eventSuccessPointAction(response){
		
		if(typeof this.#settings == 'undefined'){
			return false;
		}
		
		if(typeof this.#settings.eventSuccessPointAction == 'undefined'){
			return false;
		}
		
		this.#settings.eventSuccessPointAction(response);
		
	}
	
	/**
	 * Выводим в DOM форму с балансом бонусного счёта.
	 */
	renderSection(){
		
		var html = `<div class="ui-entity-editor-section" data-cid="user_v0cwaljr1"><div class="ui-entity-editor-section-header"><div class="ui-entity-editor-header-title"><span class="ui-entity-editor-header-title-text">Информация по баллам</span><input class="ui-entity-editor-header-title-text" style="display: none;"></div></div><div class="ui-entity-editor-section-content"><div class="ui-entity-editor-content-block" style="margin-bottom: 0px;">

<div class="row" style="display: flex;">
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 50%;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Баллов MP</label></div><div class="ui-entity-editor-content-block"><span class="fields date field-wrap"><span class="fields date field-item crm_entity_field_point_mr" id="crm_entity_field_point_mr">0</span></span></div></div>
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 50%;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Баллы MP в рублях</label></div><div class="ui-entity-editor-content-block"><span class="fields date field-wrap"><span class="fields date field-item crm_entity_field_point_mr_rub">0</span></span></div></div>
</div>
<div class="row" style="display: flex; margin-top: -13px;">
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 50%; margin: 0px 0px 0px;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Баллов Imperia</label></div><div class="ui-entity-editor-content-block"><span class="fields date field-wrap"><span class="fields date field-item crm_entity_field_point_imperia">0</span></span></div></div>
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 50%; margin: 0px 0px 0px;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Баллы Imperia в рублях</label></div><div class="ui-entity-editor-content-block"><span class="fields date field-wrap"><span class="fields date field-item crm_entity_field_point_imperia_rub">0</span></span></div></div>
</div>

<!-- .ui-alert.ui-alert-danger-->
<div class="ui-alert ui-alert-danger crm_entity_point_error_block" style="display: none;">
    <span class="ui-alert-message"><strong>Ошибка!</strong> <span class="crm_entity_point_error_block_message">Текст предупреждения находится здесь.</span></span>
</div>

<div class="row" style="display: flex;">
	<button class="ui-btn ui-btn-xs ui-btn-primary entityPointViewCountPoint">Обновить</button>
</div>

</div></div></div>`;
		
		if(this.#entityClass == false){
			$('.ui-entity-editor-column-content .ui-entity-editor-section[data-cid="main"]').after(html);
		} else {
			$(this.#entityClass).html(html);
		}
		
	}
	
}

