<?php
/**
 * Thinkbeat_SmartCustomerGrid Collection Plugin
 *
 * Enhances the customer grid collection with optimized SQL joins for order data.
 * Uses subqueries to avoid N+1 query problems and maintain grid performance.
 *
 * @category  Thinkbeat
 * @package   Thinkbeat_SmartCustomerGrid
 * @author    Thinkbeat
 * @copyright Copyright (c) 2026 Thinkbeat
 */

declare(strict_types=1);

namespace Thinkbeat\SmartCustomerGrid\Plugin\Customer\Model\ResourceModel\Grid;

use Magento\Customer\Model\ResourceModel\Grid\Collection as CustomerGridCollection;
use Magento\Framework\DB\Select;

class Collection
{
    /**
     * @var bool
     */
    private $columnsAdded = false;

    /**
     * Before load - add joins and subqueries to enhance customer grid data
     *
     * This method adds optimized SQL to fetch:
     * - Total orders count
     * - Lifetime value (sum of order grand totals)
     * - Last order date
     * - Last order ID (for fetching items later)
     * - Email domain (extracted from customer email)
     * - Customer type (Registered vs Guest identification)
     *
     * @param CustomerGridCollection $subject
     * @return void
     */
    public function beforeLoad(CustomerGridCollection $subject): void
    {
        if (!$this->columnsAdded && !$subject->isLoaded()) {
            $this->addOrderDataColumns($subject);
            $this->addEmailDomainColumn($subject);
            $this->addCustomerTypeColumn($subject);
            $this->columnsAdded = true;
        }
    }

    /**
     * Add order-related columns using optimized subqueries
     *
     * @param CustomerGridCollection $collection
     * @return void
     */
    private function addOrderDataColumns(CustomerGridCollection $collection): void
    {
        $connection = $collection->getConnection();
        $salesOrderTable = $collection->getTable('sales_order');

        // Subquery for order aggregations (count, LTV, last order date, last order ID)
        $orderStatsSubquery = $connection->select()
            ->from(
                ['so' => $salesOrderTable],
                [
                    'customer_email' => 'so.customer_email',
                    'total_orders' => new \Zend_Db_Expr('COUNT(so.entity_id)'),
                    'lifetime_value' => new \Zend_Db_Expr('SUM(so.grand_total)'),
                    'last_order_date' => new \Zend_Db_Expr('MAX(so.created_at)'),
                    'last_order_id' => new \Zend_Db_Expr(
                        'SUBSTRING_INDEX(GROUP_CONCAT(so.entity_id ORDER BY so.created_at DESC), ",", 1)'
                    )
                ]
            )
            ->where('so.state != ?', 'canceled')
            ->group('so.customer_email');

        // Join the subquery to main collection
        $collection->getSelect()->joinLeft(
            ['order_stats' => $orderStatsSubquery],
            'main_table.email = order_stats.customer_email',
            [
                'total_orders' => new \Zend_Db_Expr('IFNULL(order_stats.total_orders, 0)'),
                'lifetime_value' => new \Zend_Db_Expr('IFNULL(order_stats.lifetime_value, 0)'),
                'last_order_date' => 'order_stats.last_order_date',
                'last_order_id' => 'order_stats.last_order_id'
            ]
        );
    }

    /**
     * Add email domain column (extracted from customer email)
     *
     * @param CustomerGridCollection $collection
     * @return void
     */
    private function addEmailDomainColumn(CustomerGridCollection $collection): void
    {
        $collection->getSelect()->columns([
            'email_domain' => new \Zend_Db_Expr(
                'SUBSTRING_INDEX(main_table.email, "@", -1)'
            )
        ]);
    }

    /**
     * Add customer type column (Registered vs Guest)
     *
     * Logic:
     * - If entity_id IS NULL = Guest (from orders, added via union)
     * - If entity_id is numeric > 0 = Registered
     *
     * @param CustomerGridCollection $collection
     * @return void
     */
    private function addCustomerTypeColumn(CustomerGridCollection $collection): void
    {
        $collection->getSelect()->columns([
            'customer_type' => new \Zend_Db_Expr(
                'CASE 
                    WHEN main_table.entity_id IS NULL THEN "guest"
                    WHEN main_table.entity_id > 0 THEN "registered"
                    ELSE "guest"
                END'
            )
        ]);
    }
}
