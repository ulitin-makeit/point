<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Page\Asset;
use Brs\FinancialCard\Models\FinancialCardTable;
use Brs\IncomingPaymentEcomm\Repository\Deal;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/**
 * @var array $arResult
 * @var array $arParams
 */

global $APPLICATION;

Extension::load("ui.dialogs.messagebox");
Extension::load("ui.forms");
Extension::load("ui.buttons");

Loader::includeModule('crm');
Loader::includeModule('brs.incomingpaymentecomm');

$this->addExternalJS('/local/js/inputmask/jquery.inputmask.min.js');

// Входит ли время в интервал, нужно что бы скрывать ссылки на оплату в ночное время
$startTime = strtotime('23:45');
$endTime = strtotime('01:05');
$timePayment = strtotime(date('H:i'));

$isHideTimePayLink = $timePayment >= $startTime || $timePayment <= $endTime;
$isCredit = $arResult['IS_CREDIT'];
$isBeginningDeal = new \Bitrix\Main\Type\DateTime() >= $arResult['DEAL_BEGIN_DATE'];

$finCardPossiblePaymentStatus = [ // список статусов фин. карты в которых разрешено создание платежей
	FinancialCardTable::AUDITION_STATUS_NEW,
	FinancialCardTable::AUDITION_STATUS_AGREEMENT,
];

$isFinCardPossiblePayment = !$arParams['FIN_CARD_STATUS'] || in_array($arParams['FIN_CARD_STATUS'], $finCardPossiblePaymentStatus); // разрешена ли оплата при текущем статусе фин карты

if(IS_CURRENT_SERVER_TEST){ // #CREDIT_RELEASE#
	$isFinCardPossiblePayment = true;
}

$isCorrectionCardPossiblePayment =  $arParams['IS_CORRECTION_CARD']
	&& $arParams['FIN_CARD_STATUS'] === FinancialCardTable::AUDITION_STATUS_NEW;

$dealId = Application::getInstance()->getContext()->getRequest()->get('DEAL_ID');
$deal = new Deal($dealId);
$clientId = $deal->getClientId();

if (!$clientId) {
	?>
	<div id="incomingPayments"><div class="incoming-payments-wrapper">У Сделки нет Клиента</div></div>
	<?
	return;
}

?>
<div id="incomingPayments" data-fincard-scheme-work="<?= $arParams['FIN_CARD_SCHEME_WORK']; ?>">
	<? $APPLICATION->ShowViewContent('paymentDeferred'); ?>
	<? $APPLICATION->ShowViewContent('paymentOwes'); ?>
	<div class="create-payment-container">
		<?
		if ($isHideTimePayLink) {
			// Закрыт фин день
			echo '<div>Создать платёж невозможно так как закрыт финансовый день</div>';
		} else {
			if ($isCorrectionCardPossiblePayment) {
				// Карта коррекции
				?>
				<div>Создать платёж для Клиента</div>
				<div>
					<?if($arResult['IS_ACCESS_BUTTON_ALL']){?>
						<button id="createAdvancePayment" class="ui-btn ui-btn-primary">
							Платёж&nbsp;&nbsp;<i class="fal fa-file-alt"></i>
						</button>
					<? } ?>
				</div>
				<?
			}

			if (!$arParams['IS_CORRECTION_CARD'] && $isCredit) {
				// Кредитная схема
				?>
				<div>Создать платёж погашения Кредита</div>
				<div>
					<?if($arResult['IS_ACCESS_BUTTON_ALL']){?>
						<button id="createCreditPayment" class="ui-btn ui-btn-primary">
							Платёж по кредиту&nbsp;&nbsp;<i class="fal fa-file-alt"></i>
						</button>
					<? } ?>
				</div>
				<?
			} elseif (!$arParams['IS_CORRECTION_CARD'] && !$isCredit && $isFinCardPossiblePayment) {
				// Обычная фин карта или её отсутствие
				?>
				<div>Создать платёж для Клиента</div>
				<div>
					<?if($arResult['IS_ACCESS_BUTTON_ALL']){?>
						<button id="createAdvancePayment" class="ui-btn ui-btn-primary">
							Платёж&nbsp;&nbsp;<i class="fal fa-file-alt"></i>
						</button>
					<? } ?>
				</div>
				<?
			}
		}
		?>
	</div>
	<style>
        .cor-client-choice {
            font-size: 14px;
            font-weight: normal;
            font-family: "Helvetica Neue", Helvetica, Arial, "sans-serif";
        }

        .cor-client-type {
            display: flex;
        }

        .cor-client-type strong {
            margin-right: 15px;
            line-height: 24px;
            font-family: "Open Sans", "Helvetica Neue", Helvetica, Arial, "sans-serif";
        }

        .cor-client-type-select {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
	</style>

	<form>
		<input id="paymentType" type="hidden">
		<div class="incoming-payment-item payment-hide">

			<div class="incoming-payment-item-settings">
				<div id="methodCalculationSelect">
					<?
					switch ($arResult['CLIENT_PAYMENT']['PAYMENT_TYPE']) {
						case 'CREDIT':
							echo 'Погашение кредита';
							break;
						case 'ADVANCE':
							echo 'Аванс';
							break;
					}
					?>
				</div>
				<div>
					<div class="cor-client-choice">
						<div class="cor-client-type">
							<div><strong>Плательщик</strong></div>
							<div class="cor-client-type-select">
								<div class="selector selector-vertical">
									<div>
										<input type="radio" id="paymentByUser" name="CLIENT_PAYMENT_TYPE" value="user" checked>
										<label for="paymentByUser"><?= $arResult['CLIENT_FULL_NAME']; ?></label>
									</div>
									<?
									if ($arResult['CORP']) {
										?>
										<div>
											<input type="radio" id="paymentByCorp" name="CLIENT_PAYMENT_TYPE" value="corp">
											<label for="paymentByCorp"><?= $arResult['CORP']['FULL_TITLE']; ?></label>
										</div>
										<?
									}
									?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="selector-wrapper">
					<div class="selector selector-vertical">
						<div>
							<input type="radio" id="paymentImmediatelyRadio" name="PAYMENT_TYPE" value="card" checked>
							<label for="paymentImmediatelyRadio">Картой через Б24</label>
						</div>
						<div>
							<input type="radio" id="paymentByLinkRadio" name="PAYMENT_TYPE" value="link">
							<label for="paymentByLinkRadio">По ссылке</label>
						</div>

						<?if($arResult['CORP']){?>
							<div>
								<input type="radio" id="paymentByInvoice" name="PAYMENT_TYPE" value="invoice">
								<label for="paymentByInvoice">Оплата по счёту</label>
							</div>
						<?}?>

						<div>
							<input type="radio" id="paymentPointRadio" name="PAYMENT_TYPE" value="point">
							<label for="paymentPointRadio">Оплата баллами</label>
						</div>
					</div>
				</div>
			</div>

			<?Asset::getInstance()->addJs('/local/templates/bitrix24/js/point.js');?>
			<script type="text/javascript">
                BX.ready(function(){
                    setTimeout(function(){
                        new BrsPoint('deal', <?=$arResult['DEAL_ID']?>, '.incoming-payment-item-info-point', {
                            eventSuccessPointAction: BX.Brs.IncomingPayment.prototype.setPointAmount
                        });
                    }, 500);
                });
			</script>

			<div class="incoming-payment-item-info-point" style="display: none;">

			</div>

			<div class="incoming-payment-item-left">
				<div class="incoming-payment-input-item">
					<div class="incoming-payment-amounts-row">
						<div class="incoming-payment-amount-field incoming-payment-item-left-amount">
							<label class="incoming-payment-amount-label">Сумма в рублях</label>
							<div class="ui-ctl ui-ctl-textbox ui-ctl-wa">
								<input name="AMOUNT"
									   type="text"
									   class="ui-ctl-element"
									   placeholder="">
							</div>
						</div>
						<div class="incoming-payment-amount-field incoming-payment-item-left-point-amount">
							<label class="incoming-payment-amount-label">Сумма в баллах</label>
							<div class="ui-ctl ui-ctl-textbox ui-ctl-wa">
								<input name="AMOUNT_POINT"
									   type="text"
									   class="ui-ctl-element"
									   readonly
									   placeholder="">
							</div>
						</div>
					</div>
					<div class="incoming-payment-point-rate" style="margin-top: 5px; font-size: 12px; color: #666;">
						Курс: <span class="incoming-payment-point-rate-value">—</span>
					</div>
				</div>

				<div class="incoming-payment-item-payment-info">

					<?
					$APPLICATION->IncludeComponent(
						'brs.incomingpaymentecomm:nomenclature',
						'',
						[
							'DEAL_ID' => $dealId
						]
					);

					?>
					<div class="ui-ctl ui-ctl-textbox ui-ctl-wa">
						<input id="customEmail"
							   name="CUSTOM_EMAIL"
							   type="text"
							   onkeypress="if(event.key.replace(/([^a-zA-Z0-9\-_@\.])/g, '') == ''){ return false }"
							   class="ui-ctl-element"
							   placeholder="Свой почтовый адрес">
					</div>
					<?
					if ($arResult['EMAIL_LIST']) {
						?>
						<select size="2" id="selectClientEmail">
							<?
							foreach ($arResult['EMAIL_LIST'] as $emailNumber => $email) {
								?>
								<option value="<?= $email; ?>"
									<?
									if ($emailNumber === 0) {
										echo 'selected';
									}
									?>
								>
									<?= $email; ?>
								</option>
								<?
							}
							?>
						</select>
						<?
					} else {
						echo 'Нет почтовых ящиков у клиента';
					}
					?>
				</div>
			</div>
			<div class="incoming-payment-item-right incoming-payment-type-card">
				<div class="payment-link">
					<div class="ui-ctl ui-ctl-textbox ui-ctl-wa">
						<input name="PAYMENT_LINK"
							   type="text"
							   class="ui-ctl-element"
							   placeholder="Ссылка на оплату">
						<i class="fas fa-copy"></i>
					</div>
				</div>
				<div class="incoming-payment-item-select-card">
					<?
					$APPLICATION->IncludeComponent(
						'brs.incomingpaymentecomm:selector-card',
						'',
						[
							'ID' => 'selectorCard',
							'INPUT_NAME' => 'CARD',
							'CLIENT_ID' => $arResult['CLIENT_ID'],
							'USER_ID' => $clientId
						]
					);
					?>
				</div>
				<div class="payment-point">
					<div class="ui-ctl ui-ctl-textbox ui-ctl-wa">
						<!-- .ui-ctl.ui-ctl-after-icon.ui-ctl-dropdown >
						div.ui-ctl-after.ui-ctl-icon-angle +
						select.ui-ctl > option -->
						<div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown">
							<div class="ui-ctl-after ui-ctl-icon-angle"></div>
							<select name="POINT_TYPE" class="ui-ctl-element">
								<option value="" selected disabled>Выберите тип баллов</option>
								<option value="mr_rub">MR</option>
								<option value="imperia_rub">Imperia</option>
							</select>
						</div>
					</div>
				</div>
				<div class="incoming-payment-item-buttons">
					<?if($arResult['IS_ACCESS_BUTTON_ALL']){?>
						<button class="ui-btn ui-btn-success make-incoming-payment">Выполнить перевод</button>
						<button class="ui-btn ui-btn-success make-incoming-payment-link">Получить ссылку</button>
						<button class="ui-btn ui-btn-success make-incoming-payment-invoice">Создать платёж</button>
						<button class="ui-btn ui-btn-success make-incoming-payment-point" data-deal-id="<?=$arResult['DEAL_ID']?>" data-contact-id="<?=$arResult['CONTACT_ID']?>">Оплатить</button>
					<? } ?>
				</div>
			</div>
		</div>
	</form>
	<?
	$APPLICATION->IncludeComponent(
		'brs.incomingpaymentecomm:payments-active-list',
		'',
		[
			'DEAL_ID' => $dealId
		]
	);
	?>
	<?
	$APPLICATION->IncludeComponent(
		'brs.incomingpaymentecomm:payments-history',
		'',
		[
			'DEAL_ID' => $dealId
		]
	);
	?>
	<?
	$APPLICATION->IncludeComponent(
		'brs.incomingpaymentecomm:deal-amount-sum',
		'',
		[
			'DEAL_ID' => $dealId
		]
	);
	?>
	<?
	$APPLICATION->IncludeComponent(
		'brs.incomingpaymentecomm:payment-deferred',
		'',
		[
			'DEAL_ID' => $dealId
		]
	);
	?>
</div>

<script>
    window.pointRates = <?= json_encode($arResult['POINT_RATES']) ?>;
    window.incomingPayment = new BX.Brs.IncomingPayment();
</script>