/**
 * Класс для работы с бонусными баллами в CRM-сущностях.
 * Позволяет получать актуальную информацию о бонусном счёте клиента
 * и отображать её в карточках различных сущностей (контакт, сделка, финансовая карточка).
 * Поддерживает работу с двумя программами лояльности: MR и Imperia.
 */
class BrsPoint {
	
	#entityCode = 0; // код типа сущности в CRM (например: контакт, сделка)
	#entityId = 0; // уникальный идентификатор конкретной сущности
	#pointEis = false; // флаг, указывающий, что данные уже были загружены хотя бы раз
	#entityClass = false; // CSS-селектор или класс элемента для вставки формы с баллами
	#settings = false; // объект с дополнительными параметрами и callback-функциями
	
	/**
	 * Инициализирует класс для работы с бонусными баллами.
	 * Создаёт форму отображения баллов в DOM, загружает актуальные данные
	 * и настраивает обработчики событий для обновления информации.
	 */
	constructor(entityCode, entityId, entityClass, settings){
		
		this.#entityCode = entityCode;
		this.#entityId = entityId;
		this.#entityClass = (typeof entityClass === 'undefined') ? false : entityClass;
		this.#settings = (typeof entityClass === 'undefined') ? false : settings;
		
		this.#pointEis = false;
		
		this.renderSection(); // формируем DOM-структуру формы "Информация по баллам"
		
		this.getCountPoint(); // выполняем первичную загрузку баланса бонусного счёта
		
		this.#pointEis = true; // отмечаем, что первичная загрузка выполнена
		
		// привязываем обработчик клика на кнопку "Обновить" для повторной загрузки данных
		$('.entityPointViewCountPoint').on('click', this.getCountPoint.bind(this));
		
	}
	
	/**
	 * Получает актуальный баланс бонусного счёта через API.
	 * Отправляет AJAX-запрос к контроллеру модуля "brs.point" и обновляет
	 * отображаемые значения баллов в DOM. При ошибке показывает сообщение об ошибке.
	 */
	getCountPoint(event){
		
		// предотвращаем стандартное поведение браузера, если метод вызван по событию
		if(typeof event != 'undefined'){
			event.preventDefault();
		}
		
		// блокируем кнопку обновления на время выполнения запроса
		$('.entityPointViewCountPoint').attr('disabled', 'disabled');
		
		// отправляем запрос в контроллер получения баллов модуля "brs.point"
		BX.ajax.runAction('brs:point.api.PointController.entity', {
			data: {
				entityCode: this.#entityCode, // передаём код типа сущности
				entityId: this.#entityId, // передаём ID сущности
				pointEis: this.#pointEis // передаём флаг повторной загрузки
			}
		}).then(function (response) {
		
			// разблокируем кнопку обновления после получения ответа
			$('.entityPointViewCountPoint').removeAttr('disabled', 'disabled');
			
			// скрываем блок с ошибками, если он был показан ранее
			$('.crm_entity_point_error_block').hide(0);
		
			// если данные не получены, прерываем выполнение
			if(response.data.length === 0){
				return;
			}
			
			// вызываем callback успешного получения данных, если он определён
			this.eventSuccessPointAction(response);
			
			// обновляем отображаемые значения баллов программы MR
			$('.crm_entity_field_point_mr').text(response.data.MR);
			$('.crm_entity_field_point_mr_rub').text(response.data.MR_RUB);
			$('.crm_entity_field_point_mr_account_id').text(response.data.MR_ACCOUNT_ID || '—');
			
			// обновляем отображаемые значения баллов программы Imperia
			$('.crm_entity_field_point_imperia').text(response.data.IMPERIA);
			$('.crm_entity_field_point_imperia_rub').text(response.data.IMPERIA_RUB);
			$('.crm_entity_field_point_imperia_account_id').text(response.data.IMPERIA_ACCOUNT_ID || '—');
			
		}.bind(this), (response) => {
		
			// разблокируем кнопку обновления после ошибки
			$('.entityPointViewCountPoint').removeAttr('disabled', 'disabled');
			
			// показываем блок с сообщением об ошибке
			$('.crm_entity_point_error_block').show(0);
			
			// выводим текст ошибки, полученный от сервера
			$('.crm_entity_point_error_block_message').text(response.errors[0].message);
			
		});
		
		return false;
		
	}
	
	/**
	 * Вызывает пользовательский callback при успешном получении баллов.
	 * Позволяет внешнему коду реагировать на обновление данных о баллах.
	 */
	eventSuccessPointAction(response){
		
		// проверяем, переданы ли настройки при инициализации класса
		if(typeof this.#settings == 'undefined'){
			return false;
		}
		
		// проверяем, определена ли callback-функция в настройках
		if(typeof this.#settings.eventSuccessPointAction == 'undefined'){
			return false;
		}
		
		// вызываем пользовательскую callback-функцию с данными ответа
		this.#settings.eventSuccessPointAction(response);
		
	}
	
	/**
	 * Формирует и вставляет в DOM HTML-разметку формы с информацией о баллах.
	 * Создаёт секцию с полями для отображения баллов MR и Imperia (в баллах и рублях),
	 * номеров программ лояльности, блок для сообщений об ошибках и кнопку обновления данных.
	 */
	renderSection(){
		
		// HTML-разметка секции с информацией о бонусных баллах (компактная версия)
		var html = `
			<style>
				.brs-point-section .brs-point-grid {
					display: grid;
					grid-template-columns: repeat(3, 1fr);
					gap: 8px 12px;
					padding: 8px 0;
				}
				.brs-point-section .brs-point-field {
					min-width: 0;
				}
				.brs-point-section .brs-point-label {
					font-size: 11px;
					color: #959ca4;
					margin-bottom: 2px;
					white-space: nowrap;
					overflow: hidden;
					text-overflow: ellipsis;
				}
				.brs-point-section .brs-point-value {
					font-size: 13px;
					color: #333;
					font-weight: 500;
				}
				.brs-point-section .brs-point-program-title {
					font-size: 12px;
					font-weight: 600;
					color: #525c69;
					padding: 6px 0 4px;
					border-bottom: 1px solid #e8e8e8;
					margin-bottom: 6px;
				}
				.brs-point-section .brs-point-program-block {
					margin-bottom: 10px;
				}
				.brs-point-section .brs-point-program-block:last-of-type {
					margin-bottom: 0;
				}
				@media (max-width: 1200px) {
					.brs-point-section .brs-point-grid {
						grid-template-columns: repeat(2, 1fr);
					}
				}
				@media (max-width: 900px) {
					.brs-point-section .brs-point-grid {
						grid-template-columns: 1fr;
					}
				}
			</style>
			<div class="ui-entity-editor-section brs-point-section" data-cid="user_v0cwaljr1">
				<!-- Заголовок секции -->
				<div class="ui-entity-editor-section-header">
					<div class="ui-entity-editor-header-title">
						<span class="ui-entity-editor-header-title-text">Информация по баллам</span>
					</div>
				</div>
				
				<!-- Контент секции с полями баллов -->
				<div class="ui-entity-editor-section-content">
					<div class="ui-entity-editor-content-block" style="margin-bottom: 0px; padding: 0 12px 12px;">
						
						<!-- Блок программы MR -->
						<div class="brs-point-program-block">
							<div class="brs-point-program-title">Программа MR</div>
							<div class="brs-point-grid">
								<div class="brs-point-field">
									<div class="brs-point-label">Баллов</div>
									<div class="brs-point-value crm_entity_field_point_mr">0</div>
								</div>
								<div class="brs-point-field">
									<div class="brs-point-label">В рублях</div>
									<div class="brs-point-value crm_entity_field_point_mr_rub">0</div>
								</div>
								<div class="brs-point-field">
									<div class="brs-point-label">№ программы</div>
									<div class="brs-point-value crm_entity_field_point_mr_account_id">—</div>
								</div>
							</div>
						</div>
						
						<!-- Блок программы Imperia -->
						<div class="brs-point-program-block">
							<div class="brs-point-program-title">Программа Imperia</div>
							<div class="brs-point-grid">
								<div class="brs-point-field">
									<div class="brs-point-label">Баллов</div>
									<div class="brs-point-value crm_entity_field_point_imperia">0</div>
								</div>
								<div class="brs-point-field">
									<div class="brs-point-label">В рублях</div>
									<div class="brs-point-value crm_entity_field_point_imperia_rub">0</div>
								</div>
								<div class="brs-point-field">
									<div class="brs-point-label">№ программы</div>
									<div class="brs-point-value crm_entity_field_point_imperia_account_id">—</div>
								</div>
							</div>
						</div>
						
						<!-- Блок для отображения ошибок (по умолчанию скрыт) -->
						<div class="ui-alert ui-alert-danger crm_entity_point_error_block" style="display: none; margin-top: 10px;">
							<span class="ui-alert-message">
								<strong>Ошибка!</strong> 
								<span class="crm_entity_point_error_block_message"></span>
							</span>
						</div>
						
						<!-- Кнопка обновления баланса баллов -->
						<div style="margin-top: 10px;">
							<button class="ui-btn ui-btn-xs ui-btn-primary entityPointViewCountPoint">Обновить</button>
						</div>
						
					</div>
				</div>
			</div>
		`;
		
		// вставляем HTML в DOM в зависимости от настроек
		if(this.#entityClass == false){
			// если селектор не указан, вставляем после главной секции редактора сущности
			$('.ui-entity-editor-column-content .ui-entity-editor-section[data-cid="main"]').after(html);
		} else {
			// если селектор указан, вставляем в указанный элемент
			$(this.#entityClass).html(html);
		}
		
	}
	
}