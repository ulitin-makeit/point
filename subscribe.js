/**
 * Класс для работы с подписками БРС (Бонусная Расчетная Система).
 * Отвечает за получение списка подписок контакта через API и отображение
 * их в интерфейсе Bitrix24 в виде двух таблиц: активные и неактивные подписки.
 * 
 * Класс автоматически встраивается в карточку контакта и формирует 
 * отдельную секцию с информацией о подписках.
 */
class BrsSubscribe {
	
	// Приватное свойство для хранения ID контакта, с которым работает класс
	#contactId = 0;
	
	/**
	 * Конструктор класса инициализирует работу с подписками контакта.
	 * 
	 * Выполняет следующие действия:
	 * 1. Сохраняет ID контакта в приватное свойство
	 * 2. Рендерит секцию формы в DOM-дереве страницы
	 * 3. Загружает список подписок с сервера и отображает их
	 * 
	 * contactId - числовой идентификатор контакта в системе Bitrix24
	 */
	constructor(contactId){
		
		this.#contactId = contactId;
		
		// Создаем структуру секции "Подписки БРС" в карточке контакта
		this.renderSection();
		
		// Отправляем запрос на получение подписок и заполняем таблицы данными
		this.getSubscribes();
		
	}
	
	/**
	 * Метод получает список подписок контакта с сервера через AJAX.
	 * 
	 * Отправляет запрос к контроллеру SubscribeController.get, передавая ID контакта.
	 * При успешном получении данных вызывает метод eventSuccessSubscribeAction для
	 * отображения подписок в интерфейсе.
	 * 
	 * Если подписок нет (пустой массив), удаляет всю секцию из DOM.
	 * 
	 * event - объект события (опционально), используется для отмены стандартного поведения
	 */
	getSubscribes(event){
		
		// Если метод вызван из обработчика события, предотвращаем стандартное действие
		if(typeof event != 'undefined'){
			event.preventDefault();
		}
		
		// Выполняем AJAX-запрос к API контроллеру для получения списка подписок
		// Используем встроенный механизм Bitrix24 для работы с AJAX
		BX.ajax.runAction('brs:main.api.SubscribeController.get', {
			data: {
				contactId: this.#contactId, // Передаем ID контакта для фильтрации подписок
			}
		}).then(function (response) {
		
			// Если сервер вернул пустой массив, значит у контакта нет подписок
			// Удаляем секцию из интерфейса, чтобы не показывать пустой блок
			if(response.data.length === 0){
				
				$('#contactSubscribeList').remove();
				
				return;
				
			}
			
			// Передаем полученные данные в метод для отображения
			this.eventSuccessSubscribeAction(response.data);
			
		}.bind(this)); // Привязываем контекст this к классу
		
		return false;
		
	}
	
	/**
	 * Обработчик успешного получения подписок с сервера.
	 * 
	 * Метод разделяет подписки на две категории:
	 * - Активные (IS_ACTIVE === '1')
	 * - Неактивные (IS_ACTIVE !== '1')
	 * 
	 * Для каждой категории формирует HTML-таблицу с данными:
	 * - Номер договора (CONTRACT_NUMBER)
	 * - Название пакета (PACKAGE_NAME)
	 * - Дата начала действия (DATE_START)
	 * - Дата окончания действия (DATE_FINISH)
	 * 
	 * Если в категории нет подписок, выводится текст "Нет".
	 * 
	 * subscribes - массив объектов с данными подписок, полученный от сервера
	 */
	eventSuccessSubscribeAction(subscribes){
		
		// Переменные для накопления HTML-кода строк таблиц
		let active = '';   // HTML строк активных подписок
		let inactive = ''; // HTML строк неактивных подписок
		
		// Проходим по всем подпискам и распределяем их по категориям
		for(let key in subscribes){
			
			// Формируем строку таблицы с данными подписки
			const tableRow = `
				<tr>
					<td>${subscribes[key].CONTRACT_NUMBER}</td>
					<td>${subscribes[key].PACKAGE_NAME}</td>
					<td>${subscribes[key].DATE_START}</td>
					<td>${subscribes[key].DATE_FINISH}</td>
				</tr>
			`;
			
			// Распределяем по категориям в зависимости от статуса активности
			if(subscribes[key].IS_ACTIVE === '1'){
				active += tableRow;
			} else {
				inactive += tableRow;
			}
		}
		
		// Если активных подписок нет, устанавливаем текст "Нет"
		if(active === ''){
			active = 'Нет';
		} else {
			// Формируем полную HTML-таблицу с заголовками и накопленными строками
			active = `
				<table class="brs-contact-subscribe-table">
					<thead>
						<th>№ договора</th>
						<th>Пакет</th>
						<th>Дата начала</th>
						<th>Дата окончания</th>
					</thead>
					<tbody>
						${active}
					</tbody>
				</table>
			`;
		}
		
		// Если неактивных подписок нет, устанавливаем текст "Нет"
		if(inactive === ''){
			inactive = 'Нет';
		} else {
			// Формируем полную HTML-таблицу с заголовками и накопленными строками
			inactive = `
				<table class="brs-contact-subscribe-table">
					<thead>
						<th>№ договора</th>
						<th>Пакет</th>
						<th>Дата начала</th>
						<th>Дата окончания</th>
					</thead>
					<tbody>
						${inactive}
					</tbody>
				</table>
			`;
		}
		
		// Вставляем сформированный HTML в соответствующие контейнеры на странице
		$('#contactSubscribeListActive').html(active);
		$('#contactSubscribeListInactive').html(inactive);
		
	}
	
	/**
	 * Метод создает и встраивает в DOM секцию "Подписки БРС".
	 * 
	 * Формирует HTML-структуру секции в стиле стандартных секций Bitrix24:
	 * - Заголовок секции "Подписки БРС"
	 * - Два блока для отображения таблиц (активные и неактивные подписки)
	 * - Базовые стили для оформления таблиц
	 * 
	 * Секция вставляется после основной секции карточки контакта (data-cid="main")
	 * в правильное место DOM-дерева для корректного отображения в интерфейсе.
	 */
	renderSection(){
		
		// Формируем HTML-код секции с подписками
		// Структура повторяет стандартный формат секций редактора сущностей Bitrix24
		const html = `
			<div id="contactSubscribeList" class="ui-entity-editor-section" data-cid="user_v0cwaljr1">
				
				<!-- Заголовок секции -->
				<div class="ui-entity-editor-section-header">
					<div class="ui-entity-editor-header-title">
						<span class="ui-entity-editor-header-title-text">Подписки БРС</span>
						<input class="ui-entity-editor-header-title-text" style="display: none;">
					</div>
				</div>
				
				<!-- Содержимое секции -->
				<div class="ui-entity-editor-section-content">
					<div class="ui-entity-editor-content-block" style="margin-bottom: 0px;">
						
						<!-- Блок активных подписок -->
						<div class="row" style="display: flex;">
							<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" 
								 style="flex: 0 100%; min-width: 100%;">
								<div class="ui-entity-editor-block-before-action"></div>
								<div class="ui-entity-editor-block-draggable-btn-container"></div>
								<div class="ui-entity-editor-block-title">
									<label class="ui-entity-editor-block-title-text">Действующие</label>
								</div>
								<div class="ui-entity-editor-content-block" id="contactSubscribeListActive">
									<!-- Здесь будет отображена таблица активных подписок -->
								</div>
							</div>
						</div>
						
						<!-- Блок неактивных подписок -->
						<div class="row" style="display: flex; margin-top: -13px;">
							<div class="ui-entity-editor-content-block ui-entity-editor-content-block-field-custom-date" 
								 style="flex: 0 100%; margin: 0px 0px 0px;">
								<div class="ui-entity-editor-block-before-action"></div>
								<div class="ui-entity-editor-block-draggable-btn-container"></div>
								<div class="ui-entity-editor-block-title">
									<label class="ui-entity-editor-block-title-text">Неактивные</label>
								</div>
								<div class="ui-entity-editor-content-block" id="contactSubscribeListInactive">
									<!-- Здесь будет отображена таблица неактивных подписок -->
								</div>
							</div>
						</div>
						
					</div>
				</div>
			</div>
			
			<!-- Стили для таблиц подписок -->
			<style>
				.brs-contact-subscribe-table {
					width: 100%;
					text-align: left;
					padding: 10px;
					background-color: #fff;
					font-size: 13px;
					margin-top: 10px;
					border-bottom: 1px solid #e4e4e4;
					border-radius: 2px;
				}
			</style>
		`;
		
		// Вставляем сформированный HTML после основной секции карточки контакта
		// Используем jQuery для поиска и вставки элемента
		$('.ui-entity-editor-column-content .ui-entity-editor-section[data-cid="main"]').after(html);
		
	}
	
}