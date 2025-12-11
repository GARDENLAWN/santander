<?php

namespace Snowdog\HyvaCheckoutSantander\Payment\Method;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magewirephp\Magewire\Component;
use Aurora\Santander\ViewModel\Rates as RatesViewModel;
use Magento\Quote\Api\Data\CartInterface;

class Santander extends Component implements EvaluationInterface
{
    public bool $acceptTos = false;
    public ?string $selectedRate = null;
    public array $installmentOptions = [];

    private SessionCheckout $sessionCheckout;
    private RatesViewModel $ratesViewModel;

    public function __construct(
        SessionCheckout $sessionCheckout,
        RatesViewModel $ratesViewModel
    ) {
        $this->sessionCheckout = $sessionCheckout;
        $this->ratesViewModel = $ratesViewModel;
    }

    public function mount(): void
    {
        $quote = $this->getQuote();
        $this->installmentOptions = $this->ratesViewModel->getAvailableInstallmentOptions($quote->getAllVisibleItems());

        // Ustaw domyślnie wybraną opcję, jeśli jakaś istnieje
        if (!empty($this->installmentOptions)) {
            $this->selectedRate = $this->installmentOptions[0]['shop_number'];
            $this->updatedSelectedRate($this->selectedRate);
        }
    }

    public function updatedSelectedRate(string $shopNumber): void
    {
        $this->selectedRate = $shopNumber;
        $payment = $this->getQuote()->getPayment();
        $payment->setAdditionalInformation('santander_shop_number', $this->selectedRate);
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if ($this->getQuote()->getPayment()->getMethod() !== 'eraty_santander') {
            return $resultFactory->createSuccess();
        }

        if (empty($this->installmentOptions)) {
            return $resultFactory->createSuccess(); // Jeśli nie ma opcji rat, nie blokuj, metoda płatności i tak się nie pokaże
        }

        if (!$this->acceptTos) {
            return $this->createValidationError($resultFactory, __('Please accept the terms of service.'));
        }

        if (!$this->selectedRate) {
            return $this->createValidationError($resultFactory, __('Please select an installment option.'));
        }

        return $resultFactory->createSuccess();
    }

    private function getQuote(): CartInterface
    {
        return $this->sessionCheckout->getQuote();
    }

    private function createValidationError(EvaluationResultFactory $resultFactory, string $message): EvaluationResultInterface
    {
        $errorMessageEvent = $resultFactory->createErrorMessageEvent()
            ->withMessage($message)
            ->withCustomEvent('payment:method:error');
        return $resultFactory->createValidation('validateSantanderAcceptTos')->withFailureResult($errorMessageEvent);
    }
}
