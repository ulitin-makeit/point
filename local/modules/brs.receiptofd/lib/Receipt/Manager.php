<?php

	namespace Brs\ReceiptOfd\Receipt;

	use Brs\ReceiptOfd\Receipt;

	/**
	 * Класс реализует бизнес логику по работе с чеками.
	 */
	class Manager {
		
		private object $strategy; // объект с параметрами чека

		private int $paymentId = 0;
		private int $dealId = 0;

		/**
		 * Создаёт чек.
		 * 
		 * @return object|null
		 */
		public function create(): ?object {

			$this->createCheckFields(); // проверяем обязательные поля

			// создаём чек
			return Receipt::save([

				'STATUS' => 'NEW',
				'DEAL_ID' => $this->dealId,
				'PAYMENT_ID' => $this->paymentId,

				'RECEIPT_TYPE' => $this->strategy->getReceiptType(),
				'PAYMENT_TYPE' => $this->strategy->getPaymentType(),
				'REQUEST_RECEIPT_JSON' => $this->strategy->getRequestString(),

				'IS_REAL_RETURN_PAYMENT' => $this->strategy->hasRealReturn() // на основе реального платежа или это связующий чек

			]);

		}

		/**
		 * Создаёт предварительный чек.
		 *
		 * @return mixed
		 * @throws \Exception
		 */
		public function createPreReceipt(){

			return $this->strategy->getRequestString();
		}

		/**
		 * Проверка полей при создании чека.
		 * 
		 * @return void
		 * @throws \Exception
		 */
		private function createCheckFields(): void {

			$requiredList = [
				'strategy', 'dealId'
			];

			foreach($requiredList as $required){
				if(empty($this->$required)){
					throw new \Exception('Параметр "'.$required.'" не может быть пустым. Чек создать невозможно.');
				}
			}

		}

		public function setStrategy(object $strategy): void {
			$this->strategy = $strategy;
		}

		public function setPaymentId(int $paymentId): void {
			$this->paymentId = $paymentId;
		}

		public function setDealId(int $dealId): void {
			$this->dealId = $dealId;
		}

		public function getStrategy(): int {
			return $this->strategy;
		}

		public function getPaymentId(): int {
			return $this->paymentId;
		}

		public function getDealId(): int {
			return $this->dealId;
		}

	}
