BX.Brs.IncomingPayment = function () {
	this.popUp = {};

	this.currency = $('#finCardCurrencyId').data('currency') || 'RUB';
	this.init();
};

BX.Brs.IncomingPayment.prototype.init = function () {
	
	$('.incoming-payments-section-left form').on('submit', function (event) { event.preventDefault(); });

	$('.incoming-payment-item-settings input[name=PAYMENT_TYPE]').on('click', this.changeDisplayPaymentType);
	$('.payment-point select[name="POINT_TYPE"]').on('change', this.onPointTypeChange.bind(this));

	this.changePayment = $('.cor-client-choice .cor-client-type-select input[type="radio"]');
	this.paymentByUser = $('#paymentByUser');
	this.paymentByCorp = $('#paymentByCorp');
	
	this.createAdvancePayment = $('#createAdvancePayment');
	this.paymentType = $('.incoming-payment-item .selector-wrapper input[type="radio"]');
	this.pointRateDisplay = $('.incoming-payment-point-rate');
	this.pointRateValue = $('.incoming-payment-point-rate-value');
	
	this.containerPaymentType = $('.incoming-payment-item .selector-wrapper > div > div');
	this.paymentTypeCard = $('#paymentImmediatelyRadio');
	this.paymentTypeLink = $('#paymentByLinkRadio');
	this.paymentTypeInvoice = $('#paymentByInvoice');
	this.paymentTypePoint = $('#paymentByPoint');
	this.transactionSuccess = $('.incoming-payments-history-item.payment-success');
	this.transactionInvoice = $('.incoming-payments-history-item.payment-success[data-payment-method="INVOICE"]');
	this.transactionPoint = $('.incoming-payments-history-item.payment-success[data-payment-by-point="1"]');
	
	this.makeIncomingPaymentButton = $('.make-incoming-payment');
	this.makeIncomingPaymentLinkButton = $('.make-incoming-payment-link');
	this.makeIncomingPaymentInvoiceButton = $('.make-incoming-payment-invoice');
	this.makeIncomingPaymentPointButton = $('.make-incoming-payment-point');

	this.makeIncomingPaymentButton.on('click', this.initiatePayment.bind(this, 'makePayment'));
	this.makeIncomingPaymentLinkButton.on('click', this.initiatePayment.bind(this, 'makePaymentLink'));
	this.makeIncomingPaymentInvoiceButton.on('click', this.initiatePayment.bind(this, 'makePaymentInvoice'));
	this.makeIncomingPaymentPointButton.on('click', this.initiatePaymentPoint.bind(this, Number(this.makeIncomingPaymentPointButton.attr('data-deal-id')), Number(this.makeIncomingPaymentPointButton.attr('data-contact-id'))));
	
	this.copyLinkButton = $('.payment-link .fa-copy').on('click', this.copyPaymentLink.bind(this));
	
	this.changePayment.on('change', this.changeTypeUserPayment.bind(this)).change(); // настраиваем типы создаваемого платежа
	this.typePayerPreset(); // предустановленные настройки для типов плательщиков при создании платежа
	this.createAdvancePayment.on('click', this.changeTypeUserPayment.bind(this));
	
	setTimeout(this.inputPreset, 300); // предустановленные настройки полей в форме создания платежа
	
};

/**
 * Получает курс конвертации для указанного типа баллов.
 * 
 * @param {string} pointType - тип баллов ('mr_rub' или 'imperia_rub')
 * @returns {number|null} - курс конвертации или null если не найден
 */
BX.Brs.IncomingPayment.prototype.getPointConversionRate = function(pointType) {
	var rates = window.pointRates;
	var type = pointType.toUpperCase();
	
	if (!rates) {
		return null;
	}
	
	if (type === 'MR_RUB') {
		return rates.MR_RATE || null;
	} else if (type === 'IMPERIA_RUB') {
		return rates.IMPERIA_RATE || null;
	}
	
	return null;
};

/**
 * Обновляет отображение курса баллов при смене типа.
 * 
 * @param {string} pointType - тип баллов ('mr_rub' или 'imperia_rub')
 */
BX.Brs.IncomingPayment.prototype.updatePointRateDisplay = function(pointType) {
	if (!pointType || pointType === '') {
		this.pointRateDisplay.hide();
		return;
	}
	
	var rate = this.getPointConversionRate(pointType);
	
	if (rate === null) {
		this.pointRateDisplay.hide();
		return;
	}
	
	var typeName = pointType.toUpperCase() === 'MR_RUB' ? 'MR' : 'Imperia';
	
	this.pointRateValue.text(rate + ' руб. за 1 балл ' + typeName);
	this.pointRateDisplay.show();
};

/**
 * Предустановленные настройки полей.
 * 
 * @returns {undefined}
 */
BX.Brs.IncomingPayment.prototype.inputPreset = function(){
	
	let isCredit = $('#changeDealPayTypeCredit').prop('checked');
	let maxAmount = 9999999999;
	
	$('.incoming-payment-item input[name=AMOUNT_POINT]').inputmask(
		'currency',
		{
			min: 0,
			max: maxAmount,
			groupSeparator: ' ',
			autoUnmask: true,
			nullable: false
		}
	);
	
	if(isCredit){
		maxAmount = Number($('.payments-owes-rub').attr('data-amount-debt'))*-1;
	}
	
	$('.incoming-payment-item input[name=AMOUNT]').inputmask(
		'currency',
		{
			min: 0,
			max: maxAmount,
			groupSeparator: ' ',
			autoUnmask: true,
			nullable: false
		}
	);
}

/**
 * Предустановленные настройки по типам плательщика.
 * 
 * @returns {undefined}
 */
BX.Brs.IncomingPayment.prototype.typePayerPreset = function(){
	if(this.transactionInvoice.length > 0){ // если есть хоть один платёж с типом "Оплата по счёту", то запрещаем выбирать клиента и выбираем корп. плательщика
		
		this.paymentByCorp.click();
		
		this.paymentByUser.parent().hide(0);
		
	} else if(this.transactionSuccess.length > 0){ // если есть хоть один успешный платёж и нет платежа с типом "Оплата по счёту", то запрещаем выбирать корп. плательщика и выбираем клиента
	
		this.paymentByUser.click();
		
		this.paymentByCorp.parent().hide(0);
		
	}
}

/**
 * Осуществляем настройку типов создаваемого платежа на основе выбранного типа плательщика в платеже.
 * 
 * @returns {undefined}
 */
BX.Brs.IncomingPayment.prototype.changeTypeUserPayment = function(){
	
	if(this.paymentByUser.prop('checked')){ // если плательщик "Клиент" из сделки
		
		this.containerPaymentType.show(0);
		this.paymentTypeInvoice.parent().hide(0);
		this.paymentTypeCard.click();
		
		if(typeof window.accessAdvancePaymentType != 'undefined'){
			this.setAccessPaymentType(window.accessAdvancePaymentType, false);
		}
		
	} else { // если плательщик компания из "Место работы" в клиенте (контакте)
	
		this.containerPaymentType.hide(0);
		
		this.paymentTypeInvoice.parent().show(0);
		this.paymentTypeInvoice.click();
		
	}
	
};

BX.Brs.IncomingPayment.prototype.changeTypeFinancialCard = function (event) {
	var methodName = $(event.currentTarget).val();
	this.paymentModelView[methodName]();
};

BX.Brs.IncomingPayment.prototype.makePaymentStrategy = function (strategy, formData) {
	
	if (this[strategy]) {
		this[strategy](formData);
	} else {
		console.error('Тип оплаты [' + strategy + '] не найден');
	}
	
};

BX.Brs.IncomingPayment.prototype.changeDisplayPaymentType = function () {
	
	var infoPoint = $('.incoming-payment-item-info-point');
	var buttonAmount = $('.incoming-payment-item-left-amount');
	var buttonPointAmount = $('.incoming-payment-item-left-point-amount');
	var left = $('.incoming-payment-item-left');
	var incoming = $('.incoming-payment-item-right');
	
	incoming
		.removeClass('incoming-payment-type-card')
		.removeClass('incoming-payment-type-payment-link')
		.removeClass('incoming-payment-type-payment-invoice')
		.removeClass('incoming-payment-type-point');

	left.removeClass('incoming-payment-type-payment-invoice');
	left.removeClass('incoming-payment-type-point');
	infoPoint.hide(0);
	buttonPointAmount.hide(0);
	buttonAmount.show(0);

	if ($(this).val() === 'card') {
		incoming.addClass('incoming-payment-type-card');
	}

	if ($(this).val() === 'link') {
		incoming.addClass('incoming-payment-type-payment-link');
	}

	if ($(this).val() === 'invoice') {
		incoming.addClass('incoming-payment-type-payment-invoice');
		left.addClass('incoming-payment-type-payment-invoice');
	}
	
	if ($(this).val() === 'point') {
		
		incoming.addClass('incoming-payment-type-point');
		left.addClass('incoming-payment-type-point');
		
		infoPoint.show(0);
		
		buttonPointAmount.show(0);
		buttonAmount.hide(0);
		
	}
	
};

BX.Brs.IncomingPayment.prototype.initiatePayment = function (paymentTypeAction, event) {
	event.preventDefault();

	var formData = {};
	$(event.currentTarget).closest('form').serializeArray().forEach(function (item) {
		formData[item['name']] = item['value'];
	});

	if (!formData['AMOUNT'] || formData['AMOUNT'] <= 0) {
		this.wrong('Сумма платежа должна быть больше ноля.');
		return;
	}
	if (paymentTypeAction === 'makePayment' && !formData['CARD']) {
		this.wrong('Карта клиента должна быть выбрана.');
		return;
	}
	if (!this.getEmailReceiptSent()) {
		this.wrong('Email, для отправки чека, обязан быть указан.');
		return;
	}

	if (!this.checkValidEmailReceiptSent()) {
		this.wrong('Введённый email некорректный, проверьте что email корректный и в строке нет пробелов');
		return;
	}

	this.makePaymentStrategy(paymentTypeAction, formData);
};

BX.Brs.IncomingPayment.prototype.initiatePaymentPoint = function (dealId, contactId, event) {
	
	event.preventDefault();

	var formData = {};
	
	$(event.currentTarget).closest('form').serializeArray().forEach(function (item) {
		formData[item['name']] = item['value'];
	});
	
	// Проверка что тип баллов выбран
	if (!formData['POINT_TYPE'] || formData['POINT_TYPE'] === '') {
		this.wrong('Необходимо выбрать тип баллов.');
		return;
	}
	
	var pointMaxAmount = parseFloat($('.crm_entity_field_point_' + formData['POINT_TYPE'].replace('_rub', '') + ':first').text());

	if (contactId == 0) {
		this.wrong('Клиент должен быть прикреплён к сделке.');
		return;
	}
	
	if (!formData['AMOUNT_POINT'] || formData['AMOUNT_POINT'] <= 0) {
		this.wrong('Сумма платежа должна быть больше нуля.');
		return;
	}
	
	let amount = formData['AMOUNT_POINT'];
	
	if(amount > 0){
			
		let rate = this.getPointConversionRate(formData['POINT_TYPE']);
	
		if (rate === null) {
			this.wrong('Не удалось получить курс баллов для выбранного типа.');
			return;
		}

		amount = amount * rate;
		
	}
	
	if (Number(formData['AMOUNT_POINT']) > pointMaxAmount) {
		this.wrong('Указанная сумма баллов превышает количество баллов на бонусном счёте клиента.');
		return;
	}
	
	this.makePaymentPoint(dealId, contactId, formData['POINT_TYPE'], amount);
	
};

/**
 * Настраиваем доступ к типу "Оплата баллами" в создаваемом платеже.
 * 
 * @param {type} access параметры доступа
 * @param {type} isTimeout нужен таймаут или нет
 * @returns {undefined}
 */
BX.Brs.IncomingPayment.prototype.setAccessPaymentType = function(access, isTimeout = true){
	
	window.accessAdvancePaymentType = access;
	
	if(isTimeout){
		setTimeout(this.setAccessPaymentTypePoint.bind(this, access), 500);
	} else {
		this.setAccessPaymentTypePoint(access);
	}
	
};

/**
 * Настраиваем доступ к типу "Оплата баллами" и доступ к типу выбираемых баллов в создаваемом платеже. Либо разрешаем выбирать этот тип, либо запрещаем.
 * 
 * @param {type} access
 * @returns {undefined}
 */
BX.Brs.IncomingPayment.prototype.setAccessPaymentTypePoint = function(access){
	
	if(access.point == true){ // если разрешено создавать платежи с баллами

		$('#paymentImmediatelyRadio, #paymentByLinkRadio, #paymentByInvoice').parent().hide(0); // запрещаем создавать платежи по картам, ссылке и счёту

		$('#paymentPointRadio').click(); // выбираем тип платежа "Оплата баллами"
		
		access.pointProgramCode = (access.pointProgramCode).replace('IR', 'imperia');

		window.pointPaymentOptionValue = (access.pointProgramCode).toLowerCase() + '_rub';

		$('select[name="POINT_TYPE"] option').each(function(){
			if(window.pointPaymentOptionValue != $(this).val()){
				$(this).remove();
			}
		});
		
		if($('#paymentByCorp').length > 0){
			$('#paymentByCorp').parent().hide();
		}

	} else {	
		$('#paymentPointRadio').parent().hide(0); // скрываем возможность выбора типа платежа "Оплата баллами"		
	}
	
};

BX.Brs.IncomingPayment.prototype.setPointAmount = function (response){
	
	BX.Brs.IncomingPayment.prototype.setInputPointAmount(response);
	
	$('.payment-point select[name="POINT_TYPE"]').on('change', BX.Brs.IncomingPayment.prototype.setInputPointAmount.bind(this, response));
	
};

BX.Brs.IncomingPayment.prototype.setInputPointAmount = function (response){
	
	var inputAmountPoint = $('.incoming-payment-input-item input[name="AMOUNT_POINT"]');
	var domAmountDebp = $('.payments-owes-rub[data-amount-debt]');
	
	var inputPointType = $('.incoming-payment-type-point select[name="POINT_TYPE"]');
	
	var pointType = inputPointType.val();
	
	// Если тип не выбран — очищаем поле суммы и выходим
	if(!pointType || pointType === ''){
		inputAmountPoint.val('');
		return;
	}
	
	pointType = pointType.toUpperCase();
	
	var amountDebt = parseFloat(domAmountDebp.attr('data-amount-debt'));
	var amount = parseFloat(response.data[pointType]);
	
	if(amountDebt < 0){
		
		amountDebt = amountDebt * -1;
		
		if(amountDebt < amount && (amount - amountDebt) == 0){
			amount = amount - amountDebt;
		} else if(amountDebt < amount && (amount - amountDebt) > 0){
			amount = amountDebt;
		}
		
		if(amount > 0){
			
			let rate = BX.Brs.IncomingPayment.prototype.getPointConversionRate(pointType);

			if (rate === null) {
				this.wrong('Не удалось получить курс баллов для выбранного типа.');
				return;
			}
			
			amount = amount / rate;
			
		}
		
	} else {
		amount = 0;
	}
	
	inputAmountPoint.val(amount);
	
}

BX.Brs.IncomingPayment.prototype.makePaymentPoint = function (dealId, contactId, rateType, amount){
	
	BX.financeCardTabManager.showLoading();
	
	// отправляем запрос в контроллер списания баллов модуля "brs.point"
	BX.ajax.runAction('brs:point.api.PointPaymentController.run', {
		data: {
			dealId: dealId,
			contactId: contactId,
			rateType: rateType,
			amount: amount
		}
	}).then(function (response){

		BX.financeCardTabManager.refreshTab();

		if(response.data.length === 0){
			return;
		}
		
	}.bind(this), (response) => {
	
		BX.financeCardTabManager.hideLoading();
		
		let errMess = 'Произошла ошибка при совершении оплаты баллами<hr>';
		
		for (let errKey in response.errors) {
			errMess += response.errors[errKey]['message'] + '<hr>';
		}

		let popUp = BX.PopupWindowManager.create(
			'paymentErrorMess',
			null,
			{
				closeByEsc : true,
				content: errMess,
				autoHide: true,
				padding: 40,
				overlay: {backgroundColor: 'black', opacity: '80' }
			}
		);
		popUp.setCacheable(false);
		popUp.show();

	});
	
};

/**
 * Обработчик смены типа баллов в селекте.
 */
BX.Brs.IncomingPayment.prototype.onPointTypeChange = function(event) {
	var pointType = $(event.currentTarget).val();
	this.updatePointRateDisplay(pointType);
};

BX.Brs.IncomingPayment.prototype.prepareParams = function () {
	const clientScheme = $('.client-payment-type-selects input:checked').val();
	const paymentType = $('#paymentType').val();
	const receiptType = $('#receiptType').val();

	return {
		paymentType: paymentType,
		receiptType: receiptType,
		clientScheme: clientScheme
	};
}

BX.Brs.IncomingPayment.prototype.makePayment = function (formData) {
	BX.financeCardTabManager.showLoading();

	const params = this.prepareParams();

	if (!params) {
		return;
	}

	var request = BX.ajax.runAction(
		'brs:incomingpaymentecomm.api.PayController.initiatePayment',
		{
			data: {
				amount: formData['AMOUNT'],
				dealId: $('#financialCardDealId').val(),
				options: {
					paymentType: params.paymentType,
					currency: this.currency,
					nomenclature: $('#receiptNomenclature').val(),
					email: this.getEmailReceiptSent(),
					receiptType: params.receiptType,
					cardId: formData['CARD']
				}
			}
		}
	);

	request.then(
		function () {
			$('#btnCloseDeal').addClass('ui-btn-disabled');
			BX.financeCardTabManager.refreshTab();
		},
		function (response) {
			BX.financeCardTabManager.hideLoading();

			let errMess = 'Произошла ошибку при совершении оплаты<hr>';
			for (let errKey in response.errors) {
				errMess += response.errors[errKey]['message'] + '<hr>';
			}

			let popUp = BX.PopupWindowManager.create(
				'paymentErrorMess',
				null,
				{
					closeByEsc : true,
					content: errMess,
					autoHide: true,
					padding: 40,
					overlay: {backgroundColor: 'black', opacity: '80' }
				}
			);
			popUp.setCacheable(false);
			popUp.show();
		}
	);
};

BX.Brs.IncomingPayment.prototype.makePaymentLink = function (formData) {
	BX.financeCardTabManager.showLoading();

	const params = this.prepareParams();
	if (!params) {
		return;
	}

	var request = BX.ajax.runAction(
		'brs:incomingpaymentecomm.api.PayController.initiatePaymentLink',
		{
			data: {
				amount: formData['AMOUNT'],
				dealId: $('#financialCardDealId').val(),
				options: {
					clientScheme: params.clientScheme,
					paymentType: params.paymentType,
					currency: this.currency,
					email: this.getEmailReceiptSent(),
					receiptType: params.receiptType
				}
			}
		}
	);

	request.then(response => {
		$('#btnCloseDeal').addClass('ui-btn-disabled');
		$('input[name=PAYMENT_LINK]').val(response.data);
		BX.financeCardTabManager.hideLoading();
	});
};

BX.Brs.IncomingPayment.prototype.makePaymentInvoice = function (formData) {
	
	BX.financeCardTabManager.showLoading();

	const params = this.prepareParams();
	
	if (!params) {
		return;
	}

	var request = BX.ajax.runAction(
		'brs:incomingpaymentecomm.api.PayController.initiatePaymentInvoice',
		{
			data: {
				
				amount: formData['AMOUNT'],
				dealId: $('#financialCardDealId').val(),
				
				options: {
					clientScheme: params.clientScheme
				}
				
			}
		}
	);

	request.then(response => {
		
		$('#btnCloseDeal').addClass('ui-btn-disabled');
		
		BX.financeCardTabManager.refreshTab();
		BX.financeCardTabManager.hideLoading();
		
	});
	
};

BX.Brs.IncomingPayment.prototype.wrong = function (message) {
	BX.UI.Dialogs.MessageBox.show(
		{
			message: message,
			modal: true,
			buttons: BX.UI.Dialogs.MessageBoxButtons.OK
		}
	);
};

BX.Brs.IncomingPayment.prototype.copyPaymentLink = function () {
	$('input[name=PAYMENT_LINK]').trigger('select');
	document.execCommand('copy');
};

BX.Brs.IncomingPayment.prototype.getEma = function () {
	$('input[name=PAYMENT_LINK]').trigger('select');
	document.execCommand('copy');
};


BX.Brs.IncomingPayment.prototype.getEmailReceiptSent = function () {
	var resultEmail;
	var customEmail = $('#customEmail').val();
	var clientEmail = $('#selectClientEmail option:selected').val()

	if (customEmail) {
		resultEmail = customEmail;
	}
	else {
		resultEmail = clientEmail;
	}

	return resultEmail;
};

BX.Brs.IncomingPayment.prototype.checkValidEmailReceiptSent = function () {
	const EMAIL_REGEXP = /^(([^<>()[\].,;:\s@"]+(\.[^<>()[\].,;:\s@"]+)*)|(".+"))@(([^<>()[\].,;:\s@"]+\.)+[^<>()[\].,;:\s@"]{2,})$/iu;

	var resultEmail;
	var customEmail = $('#customEmail').val();
	var clientEmail = $('#selectClientEmail option:selected').val()

	if (customEmail) {
		resultEmail = customEmail;
	}
	else {
		resultEmail = clientEmail;
	}

	return EMAIL_REGEXP.test(resultEmail);
};

BX.Brs.IncomingPayment.prototype.showInfo = function (id, message) {
	if (!this.popUp[id]) {
		this.popUp[id] = new BX.PopupWindow(id, null, {
			content: message,
			autoHide: true,
			closeByEsc: true,
			padding: 40,
			overlay: {backgroundColor: 'black', opacity: '80'}
		});
	}

	this.popUp[id].show();

	BX.financeCardTabManager.hideLoading();
};
