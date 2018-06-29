<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Service\PurchaseFlow;

use Doctrine\Common\Collections\ArrayCollection;
use Eccube\Entity\ItemHolderInterface;
use Eccube\Entity\ItemInterface;

class PurchaseFlow
{
    /**
     * @var ArrayCollection|ItemHolderPreprocessor[]
     */
    protected $itemHolderProcessors;

    /**
     * @var ArrayCollection|ItemPreprocessor[]
     */
    protected $itemProcessors;

    /**
     * @var ArrayCollection|PurchaseProcessor[]
     */
    protected $purchaseProcessors;

    /**
     * @var ArrayCollection|ItemValidator[]
     */
    protected $itemValidators;

    /**
     * @var ArrayCollection|ItemHolderValidator[]
     */
    protected $itemHolderValidators;

    /**
     * @var ArrayCollection|ItemPreprocessor[]
     */
    protected $itemPreprocessors;

    /**
     * @var ArrayCollection|ItemHolderPreprocessor[]
     */
    protected $itemHolderPreprocessors;

    public function __construct()
    {
        $this->itemProcessors = new ArrayCollection();
        $this->itemHolderProcessors = new ArrayCollection();
        $this->purchaseProcessors = new ArrayCollection();
        $this->itemValidators = new ArrayCollection();
        $this->itemHolderValidators = new ArrayCollection();
        $this->itemPreprocessors = new ArrayCollection();
        $this->itemHolderPreprocessors = new ArrayCollection();
    }

    public function setItemProcessors(ArrayCollection $processors)
    {
        $this->itemProcessors = $processors;
    }

    public function setItemHolderProcessors(ArrayCollection $processors)
    {
        $this->itemHolderProcessors = $processors;
    }

    public function setPurchaseProcessors(ArrayCollection $processors)
    {
        $this->purchaseProcessors = $processors;
    }

    public function setItemValidators(ArrayCollection $itemValidators)
    {
        $this->itemValidators = $itemValidators;
    }

    public function setItemHolderValidators(ArrayCollection $itemHolderValidators)
    {
        $this->itemHolderValidators = $itemHolderValidators;
    }

    public function setItemPreprocessors(ArrayCollection $itemPreprocessors)
    {
        $this->itemPreprocessors = $itemPreprocessors;
    }

    public function setItemHolderPreprocessors(ArrayCollection $itemHolderPreprocessors)
    {
        $this->itemHolderPreprocessors = $itemHolderPreprocessors;
    }

    public function validate(ItemHolderInterface $itemHolder, PurchaseContext $context)
    {
        $this->calculateAll($itemHolder);

        $flowResult = new PurchaseFlowResult($itemHolder);

        foreach ($itemHolder->getItems() as $item) {
            foreach ($this->itemProcessors as $itemProcessor) {
                $result = $itemProcessor->process($item, $context);
                $flowResult->addProcessResult($result);
            }
        }

        $this->calculateAll($itemHolder);

        foreach ($this->itemHolderProcessors as $holderProcessor) {
            $result = $holderProcessor->process($itemHolder, $context);
            $flowResult->addProcessResult($result);
        }

        $this->calculateAll($itemHolder);

        foreach ($itemHolder->getItems() as $item) {
            foreach ($this->itemValidators as $itemValidator) {
                $result = $itemValidator->execute($item, $context);
                $flowResult->addProcessResult($result);
            }
        }

        $this->calculateAll($itemHolder);

        foreach ($this->itemHolderValidators as $itemHolderValidator) {
            $result = $itemHolderValidator->execute($itemHolder, $context);
            $flowResult->addProcessResult($result);
        }

        $this->calculateAll($itemHolder);

        return $flowResult;
    }

    /**
     * @param ItemHolderInterface $target
     * @param PurchaseContext     $context
     *
     * @throws PurchaseException
     */
    public function purchase(ItemHolderInterface $target, PurchaseContext $context)
    {
        foreach ($this->purchaseProcessors as $processor) {
            $processor->commit($target, $context);
        }
    }

    public function addPurchaseProcessor(PurchaseProcessor $processor)
    {
        $this->purchaseProcessors[] = $processor;
    }

    public function addItemHolderProcessor(ItemHolderPreprocessor $prosessor)
    {
        $this->itemHolderProcessors[] = $prosessor;
    }

    public function addItemProcessor(ItemPreprocessor $prosessor)
    {
        $this->itemProcessors[] = $prosessor;
    }

    public function addItemValidator(ItemValidator $itemValidator)
    {
        $this->itemValidators[] = $itemValidator;
    }

    public function addItemHolderValidator(ItemHolderValidator $itemHolderValidator)
    {
        $this->itemHolderValidators[] = $itemHolderValidator;
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateTotal(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()->reduce(function ($sum, ItemInterface $item) {
            $sum += $item->getPriceIncTax() * $item->getQuantity();

            return $sum;
        }, 0);
        $itemHolder->setTotal($total);
        // TODO
        if ($itemHolder instanceof \Eccube\Entity\Order) {
            // Order には PaymentTotal もセットする
            $itemHolder->setPaymentTotal($total);
        }
    }

    protected function calculateSubTotal(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()
            ->getProductClasses()
            ->reduce(function ($sum, ItemInterface $item) {
                $sum += $item->getPriceIncTax() * $item->getQuantity();

                return $sum;
            }, 0);
        // TODO
        if ($itemHolder instanceof \Eccube\Entity\Order) {
            // Order の場合は SubTotal をセットする
            $itemHolder->setSubTotal($total);
        }
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateDeliveryFeeTotal(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()
            ->getDeliveryFees()
            ->reduce(function ($sum, ItemInterface $item) {
                $sum += $item->getPriceIncTax() * $item->getQuantity();

                return $sum;
            }, 0);
        $itemHolder->setDeliveryFeeTotal($total);
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateDiscount(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()
            ->getDiscounts()
            ->reduce(function ($sum, ItemInterface $item) {
                $sum += $item->getPriceIncTax() * $item->getQuantity();

                return $sum;
            }, 0);
        // TODO 後方互換のため discount には正の整数を代入する
        $itemHolder->setDiscount($total * -1);
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateCharge(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()
            ->getCharges()
            ->reduce(function ($sum, ItemInterface $item) {
                $sum += $item->getPriceIncTax() * $item->getQuantity();

                return $sum;
            }, 0);
        $itemHolder->setCharge($total);
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateTax(ItemHolderInterface $itemHolder)
    {
        $total = $itemHolder->getItems()
            ->reduce(function ($sum, ItemInterface $item) {
                $sum += ($item->getPriceIncTax() - $item->getPrice()) * $item->getQuantity();

                return $sum;
            }, 0);
        $itemHolder->setTax($total);
    }

    /**
     * @param ItemHolderInterface $itemHolder
     */
    protected function calculateAll(ItemHolderInterface $itemHolder)
    {
        $this->calculateDeliveryFeeTotal($itemHolder);
        $this->calculateCharge($itemHolder);
        $this->calculateDiscount($itemHolder);
        $this->calculateSubTotal($itemHolder); // Order の場合のみ
        $this->calculateTax($itemHolder);
        $this->calculateTotal($itemHolder);
    }
}
