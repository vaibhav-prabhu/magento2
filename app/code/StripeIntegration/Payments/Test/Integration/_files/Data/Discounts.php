<?php

use Magento\Framework\ObjectManagerInterface;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

// $10 discount

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('$10 discount')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT_FOR_CART)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_discount')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

// 10% off

$rule = $objectManager->create(RuleInterface::class);
$rule->setName('10% discount')
    ->setIsAdvanced(true)
    ->setStopRulesProcessing(false)
    ->setDiscountQty(10)
    ->setCustomerGroupIds([0])
    ->setWebsiteIds([1])
    ->setCouponType(RuleInterface::COUPON_TYPE_SPECIFIC_COUPON)
    ->setSimpleAction(RuleInterface::DISCOUNT_ACTION_BY_PERCENT)
    ->setDiscountAmount(10)
    ->setIsActive(true);

$ruleRepository = $objectManager->get(RuleRepositoryInterface::class);
$rule = $ruleRepository->save($rule);

$coupon = $objectManager->create(CouponInterface::class);
$coupon->setCode('10_percent')
    ->setRuleId($rule->getRuleId());

$couponRepository = $objectManager->get(CouponRepositoryInterface::class);
$coupon = $couponRepository->save($coupon);

