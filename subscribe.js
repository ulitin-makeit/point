/**
 * Класс получает бонусные баллы и выводит его в сущности (контакт, сделка, финансовая краточка).
 */
class BrsSubscribe {
	
	#contactId = 0; // идентификатор контакта
	
	/**
	 * Получаем подписки и выводим его в DOM узел.
	 */
	constructor(contactId){
		
		this.#contactId = contactId;
		
		this.renderSection(); // формируем DOM формы "Информация по баллам"
		
		this.getSubscribes(); // получаем подписки и устанавливаем в форму
		
	}
	
	/**
	 * Метод получает список подписок и устанавливает в форму.
	 */
	getSubscribes(event){
		
		if(typeof event != 'undefined'){
			event.preventDefault();
		}
		
		// отправляем запрос в контроллер получения подписок
		BX.ajax.runAction('brs:main.api.SubscribeController.get', {
			data: {
				contactId: this.#contactId,
			}
		}).then(function (response) {
		
			if(response.data.length === 0){
				
				$('#contactSubscribeList').remove();
				
				return;
				
			}
			
			this.eventSuccessSubscribeAction(response.data);
			
		}.bind(this));
		
		return false;
		
	}
	
	/**
	 * Событие успешного получения подписок
	 */
	eventSuccessSubscribeAction(subscribes){
		
		let active = '';
		let inactive = '';
		
		for(let key in subscribes){
			if(subscribes[key].IS_ACTIVE === '1'){
				active += '<tr><td>' + subscribes[key].CONTRACT_NUMBER + '</td><td>' + subscribes[key].PACKAGE_NAME + '</td><td>' + subscribes[key].DATE_START + '</td><td>' + subscribes[key].DATE_FINISH + '</td></tr>';
			} else {
				inactive += '<tr><td>' + subscribes[key].CONTRACT_NUMBER + '</td><td>' + subscribes[key].PACKAGE_NAME + '</td><td>' + subscribes[key].DATE_START + '</td><td>' + subscribes[key].DATE_FINISH + '</td></tr>';
			}
		}
		
		// если значения пустые, то устанавливаем значение "Нет"
		if(active === ''){
			active = 'Нет';
		} else {
			active = `<table class="brs-contact-subscribe-table">
	<thead>
		<th>№ договора</th>
		<th>Пакет</th>
		<th>Дата начала</th>
		<th>Дата окончания</th>
	</thead>
	<tbody>
		` + active + `
	</tbody>
</table>`;
		}
		
		if(inactive === ''){
			inactive = 'Нет';
		} else {
			inactive = `<table class="brs-contact-subscribe-table">
	<thead>
		<th>№ договора</th>
		<th>Пакет</th>
		<th>Дата начала</th>
		<th>Дата окончания</th>
	</thead>
	<tbody>
		` + inactive + `
	</tbody>
</table>`;
		}
		
		$('#contactSubscribeListActive').html(active);
		$('#contactSubscribeListInactive').html(inactive);
		
	}
	
	/**
	 * Выводим в DOM форму с подписками.
	 */
	renderSection(){
		
		var html = `<div id="contactSubscribeList" class="ui-entity-editor-section" data-cid="user_v0cwaljr1"><div class="ui-entity-editor-section-header"><div class="ui-entity-editor-header-title"><span class="ui-entity-editor-header-title-text">Подписки БРС</span><input class="ui-entity-editor-header-title-text" style="display: none;"></div></div><div class="ui-entity-editor-section-content"><div class="ui-entity-editor-content-block" style="margin-bottom: 0px;">

<div class="row" style="display: flex;">
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 100%; min-width: 100%;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Действующие</label></div><div class="ui-entity-editor-content-block" id="contactSubscribeListActive">
</div></div>
</div>

<div class="row" style="display: flex; margin-top: -13px;">
<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" style="flex: 0 100%; margin: 0px 0px 0px;"><div class="ui-entity-editor-block-before-action"></div><div class="ui-entity-editor-block-draggable-btn-container"></div><div class="ui-entity-editor-block-title"><label class="ui-entity-editor-block-title-text">Неактивные</label></div><div class="ui-entity-editor-content-block" id="contactSubscribeListInactive"></div></div>
</div>

</div></div></div>

<style>
	.brs-contact-subscribe-table { width: 100%; text-align: left; padding: 10px; background-color: #fff; font-size: 13px; margin-top: 10px; border-bottom: 1px solid #e4e4e4; border-radius: 2px; }
</style>

`;
		
		$('.ui-entity-editor-column-content .ui-entity-editor-section[data-cid="main"]').after(html);
		
	}
	
}

