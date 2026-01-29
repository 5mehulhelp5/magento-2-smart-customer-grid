<?php
/**
 * Thinkbeat_SmartCustomerGrid Add Guest Customers to Customer Grid
 *
 * Enhances the customer grid to show BOTH registered and guest customers.
 * Guest customers are extracted from orders where customer_id IS NULL.
 *
 * @category  Thinkbeat
 * @package   Thinkbeat_SmartCustomerGrid
 * @author    Thinkbeat
 * @copyright Copyright (c) 2026 Thinkbeat
 */

declare(strict_types=1);

namespace Thinkbeat\SmartCustomerGrid\Plugin;

use Magento\Customer\Model\ResourceModel\Grid\Collection as CustomerGridCollection;

class AddGuestCustomersToGrid
{
    /**
     * @var bool
     */
    private $guestsAdded = false;

    /**
     * After _renderFiltersBefore - add guest customers via UNION
     *
     * @param CustomerGridCollection $subject
     * @return void
     */
    public function beforeLoad(CustomerGridCollection $subject): void
    {
        if (!$this->guestsAdded && !$subject->isLoaded()) {
            $this->addGuestCustomersUnion($subject);
            $this->guestsAdded = true;
        }
    }

    /**
     * Map inner query WHERE clauses to Outer Query context
     * Replaces table aliases (order_stats, etc) with 'main_table'
     * or appropriate alias.
     *
     * @param string $wherePart
     * @return string
     */
    private function mapWherePartToOuter(string $wherePart): string
    {
        // 1. Replace main_table (customer_grid_flat) -> main_table (Outer wrapper)
        // No change needed usually, but explicit check main_table matches.
        
        // 2. Replace order_stats.col -> main_table.col
        $wherePart = str_replace(['`order_stats`.', 'order_stats.'], ['`main_table`.', 'main_table.'], $wherePart);
        
        // 3. Handle Region/State mapping
        // rnt.name -> billing_region (usually aliased as billing_region in select)
        // -> main_table.billing_region
        // rct.default_name -> billing_region
        
        // Handle backticked and non-backticked variants
        $patterns = [
            '/`?rnt`?\.`?name`?/',
            '/`?rct`?\.`?default_name`?/',
            '/IFNULL\([^,]+,\s*IFNULL\([^,]+,\s*`?main_table`?\.`?billing_region`?\)\)/i'
        ];
        
        $replacements = [
            'main_table.billing_region',
            'main_table.billing_region',
            'main_table.billing_region'
        ];
        
        // Simple string replacement first for exact matches
        $wherePart = str_replace(
            ['`rnt`.`name`', 'rnt.name', '`rct`.`default_name`', 'rct.default_name'],
            'main_table.billing_region',
            $wherePart
        );

        // Then clean up complex IFNULL expressions that become redundant
        // IFNULL(main_table.billing_region,
        // IFNULL(main_table.billing_region, main_table.billing_region))
        // collapses to just main_table.billing_region implicitly by database,
        // but let's clean it up for readability/safety?
        // Actually, the database optimizer handles IFNULL(x, x) -> x usually.
        // But the Outer Query just needs to filter on the COLUMN in the union.
        
        // If the WHERE clause was: IFNULL(rnt.name, IFNULL(rct.default_name, main_table.billing_region)) LIKE ...
        // After replace: IFNULL(main_table.billing_region,
        // IFNULL(main_table.billing_region, main_table.billing_region)) LIKE ...
        // This is valid SQL.
        
        return $wherePart;
    }

    /**
     * Get the expression for a guest column based on the column name/alias
     *
     * @param string $columnName
     * @return string|\Zend_Db_Expr
     */
    private function getGuestColumnExpression(string $columnName)
    {
        switch ($columnName) {
            case 'entity_id':
                // Use ROW_NUMBER() to generate sequential IDs (1, 2, 3...) for guests based on their first order time.
                // Add the Max Registered ID to offset them to start after the last registered customer.
                // This eliminates gaps caused by multiple orders per customer.
                return new \Zend_Db_Expr(
                    'ROW_NUMBER() OVER (ORDER BY MIN(so.entity_id)) + ' .
                    '(SELECT IFNULL(MAX(entity_id), 0) FROM customer_grid_flat)'
                );
            case 'name':
                return new \Zend_Db_Expr('CONCAT(so.customer_firstname, " ", so.customer_lastname)');
            case 'email':
                return 'so.customer_email';
            case 'group_id':
                return new \Zend_Db_Expr('0');
            case 'created_at':
                return new \Zend_Db_Expr('MIN(so.created_at)');
            case 'website_id':
                return 'so.store_id';
            case 'created_in':
                return new \Zend_Db_Expr('"Guest Checkout"');
            case 'billing_firstname':
                return 'so.customer_firstname';
            case 'billing_lastname':
                return 'so.customer_lastname';
            // Order statistics columns (added by Collection plugin)
            case 'total_orders':
                return new \Zend_Db_Expr('COUNT(so.entity_id)');
            case 'lifetime_value':
                return new \Zend_Db_Expr('SUM(so.grand_total)');
            case 'last_order_date':
                return new \Zend_Db_Expr('MAX(so.created_at)');
            case 'last_order_id':
                return new \Zend_Db_Expr(
                    'SUBSTRING_INDEX(GROUP_CONCAT(so.entity_id ORDER BY so.created_at DESC), ",", 1)'
                );
            case 'email_domain':
                return new \Zend_Db_Expr('SUBSTRING_INDEX(so.customer_email, "@", -1)');
            case 'customer_type':
                return new \Zend_Db_Expr('"guest"');
            case 'billing_region':
                // Use SUBSTRING_INDEX trick to get the region from the latest order
                return new \Zend_Db_Expr(
                    'SUBSTRING_INDEX(GROUP_CONCAT(soa.region ORDER BY so.created_at DESC SEPARATOR "||"), "||", 1)'
                );
            case 'billing_telephone':
                return new \Zend_Db_Expr(
                    'SUBSTRING_INDEX(GROUP_CONCAT(soa.telephone ORDER BY so.created_at DESC SEPARATOR "||"), "||", 1)'
                );
            case 'billing_postcode':
                return new \Zend_Db_Expr(
                    'SUBSTRING_INDEX(GROUP_CONCAT(soa.postcode ORDER BY so.created_at DESC SEPARATOR "||"), "||", 1)'
                );
            case 'billing_country_id':
                // Country ID is short, so this should be fine
                return new \Zend_Db_Expr(
                    'SUBSTRING_INDEX(GROUP_CONCAT(soa.country_id ORDER BY so.created_at DESC SEPARATOR "||"), "||", 1)'
                );
            default:
                return new \Zend_Db_Expr('NULL');
        }
    }

    /**
     * Add guest customers from orders using UNION
     *
     * @param CustomerGridCollection $collection
     * @return void
     */
    private function addGuestCustomersUnion(CustomerGridCollection $collection): void
    {
        $connection = $collection->getConnection();
        $salesOrderTable = $collection->getTable('sales_order');
        $customerGridTable = $collection->getTable('customer_grid_flat');

        // Get the current select (registered customers)
        $originalSelect = $collection->getSelect(); // Don't clone yet, we might read parts
        
        // 1. Identify all explicit aliases in the original select to avoid collision with expanded *
        $selectParts = $originalSelect->getPart(\Magento\Framework\DB\Select::COLUMNS);
        $explicitAliases = [];
        foreach ($selectParts as $columnInfo) {
            $columnExpr = $columnInfo[1];
            $columnAlias = $columnInfo[2];
            
            if ($columnExpr !== '*') {
                $alias = $columnAlias;
                if (empty($alias) && is_string($columnExpr)) {
                    $alias = $columnExpr;
                }
                if (!empty($alias)) {
                    $explicitAliases[] = $alias;
                }
            }
        }

        // 2. Build a Clean Registered Select (explicit columns only, expanded wildcards)
        // We start with a clone of original but we will reset its columns
        $cleanRegisteredSelect = clone $originalSelect;
        $cleanRegisteredSelect->reset(\Magento\Framework\DB\Select::COLUMNS);
        
        // Fix: Extract 'customer_type' filters AND other filters to Apply on Outer Query
        // 1. 'customer_type' must be removed from inner query (it's an alias).
        // 2. Other filters (Phone, Zip, etc) must be kept in inner (for Registered perf)
        //    AND added to outer (to filter Guests).
        //    We need to map table aliases (order_stats, etc) to 'main_table' for the outer query.

        $whereParts = $cleanRegisteredSelect->getPart(\Magento\Framework\DB\Select::WHERE);
        $outerWhereParts = [];
        $newInnerWhereParts = [];
        
        // computed/joined columns that might fail if filtered as main_table.col in inner query
        // or aliases that shouldn't be in the WHERE of the inner query if they aren't real columns
        $computedColumns = [
            'customer_type',
            'last_order_date',
            'lifetime_value',
            'total_orders',
            'last_order_id'
        ];

        foreach ($whereParts as $wherePart) {
            $extract = false;
            foreach ($computedColumns as $col) {
                if (strpos($wherePart, $col) !== false) {
                    $extract = true;
                    break;
                }
            }
            
            if ($extract) {
                // Determine if we should add it to outer
                $outerWhereParts[] = $this->mapWherePartToOuter($wherePart);
                // DO NOT add to inner - filtering on "main_table.last_order_date" fails in inner
                // because last_order_date is from order_stats join, not customer_grid_flat
            } else {
                $newInnerWhereParts[] = $wherePart;
                // ALSO add to outer (mapped) for guests
                $outerWhereParts[] = $this->mapWherePartToOuter($wherePart);
            }
        }
        
        $cleanRegisteredSelect->setPart(\Magento\Framework\DB\Select::WHERE, $newInnerWhereParts);

        // 3. Build Guest Select
        $guestSelect = $connection->select()
            ->from(['so' => $salesOrderTable], []);
        
        // Context for wildcard expansion
        $gridTableColumns = null;
        
        // Logic to populate both selects synchronously
        foreach ($selectParts as $columnInfo) {
            $tableAlias = $columnInfo[0];
            $columnExpr = $columnInfo[1];
            $columnAlias = $columnInfo[2];
            
            if ($columnExpr === '*') {
                 // Expand wildcard from customer_grid_flat
                if ($gridTableColumns === null) {
                    $gridTableColumns = array_keys($connection->describeTable($customerGridTable));
                }
                 
                foreach ($gridTableColumns as $colName) {
                    // Check if this column is overridden by an explicit alias later
                    // Handle both simple 'col' and 'table.col' formats in explicitAliases
                    $isExplicit = false;
                    foreach ($explicitAliases as $alias) {
                        if ($alias === $colName || $alias === 'main_table.' . $colName) {
                            $isExplicit = true;
                            break;
                        }
                    }
                     
                    if ($isExplicit) {
                        continue; // Skip it, it will be added by the explicit part
                    }
                     
                    // Add to Registered
                    $cleanRegisteredSelect->columns([$colName => 'main_table.' . $colName]);

                    // Add to Guest
                    $expr = $this->getGuestColumnExpression($colName);
                    $guestSelect->columns([$colName => $expr]);
                }
            } else {
                // Explicit column
                // Just add it back to Registered (it was there, but we reset columns)
                // We must preserve the original expression/alias structure
                
                // Reconstruct the column definition for cleanRegisteredSelect
                // $columnInfo is [table, expr, alias]
                // Zend_Db_Select::columns($cols, $correlationName)
                
                $colName = $columnAlias;
                if (empty($colName) && is_string($columnExpr)) {
                     $colName = $columnExpr;
                }
                
                // Add to Registered
                // If it has a table alias, we might need to respect it,
                // but we are working on the clone so table aliases should be valid.
                // However, ->columns() takes an associative array or string.
                if (!empty($columnAlias)) {
                    $cleanRegisteredSelect->columns([$columnAlias => $columnExpr], $tableAlias);
                } else {
                    $cleanRegisteredSelect->columns($columnExpr, $tableAlias);
                }

                // Add to Guest
                if (!empty($colName)) {
                    $expr = $this->getGuestColumnExpression($colName);
                    $guestSelect->columns([$colName => $expr]);
                } else {
                    // Fallback for expression without alias? Guests need an alias to match structure.
                    // Generally shouldn't happen in Grid. Attempt to guess or skip?
                    continue;
                }
            }
        }

        // Create guest customers subquery from sales_order
        // Apply WHERE clauses to the existing $guestSelect
        $guestSelect
            ->joinLeft(
                ['soa' => $collection->getTable('sales_order_address')],
                'so.entity_id = soa.parent_id AND soa.address_type = "billing"',
                []
            )
            ->where('so.customer_id IS NULL')
            ->where('so.customer_is_guest = ?', 1)
            ->where('so.customer_email IS NOT NULL')
            ->where('so.customer_email != ?', '')
            ->where('so.state != ?', 'canceled')
            // Exclude emails that already exist as registered customers
            ->where(
                'so.customer_email NOT IN (?)',
                $connection->select()
                    ->from($customerGridTable, ['email'])
            )
            ->group('so.customer_email');

        // Create UNION query
        $unionSelect = $connection->select()->union(
            [$cleanRegisteredSelect, $guestSelect],
            \Magento\Framework\DB\Select::SQL_UNION_ALL
        );

        // Reset and apply union
        $collection->getSelect()->reset();
        $collection->getSelect()->from(
            ['main_table' => new \Zend_Db_Expr('(' . $unionSelect . ')')],
            '*'
        );
        
        // Re-apply extracted filters to outer query
        foreach ($outerWhereParts as $wherePart) {
             // Remove leading AND/OR to apply cleanly
             $cleanPart = preg_replace('/^\s*(AND|OR)\s+/', '', trim($wherePart));
             // Remove outer parentheses if they wrap the whole expression (naive check)
            if (substr($cleanPart, 0, 1) === '(' && substr($cleanPart, -1) === ')') {
                $cleanPart = substr($cleanPart, 1, -1);
            }
             $collection->getSelect()->where($cleanPart);
        }
    }
}
