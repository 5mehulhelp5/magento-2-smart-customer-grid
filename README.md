# Thinkbeat_SmartCustomerGrid

**Version:** 1.0.0  
**Magento Compatibility:** 2.4.x  
**PHP Compatibility:** 8.1+

## Overview

A production-ready Magento 2 module that enhances the Admin Customer Grid with valuable business insights. Adds 7 powerful columns with zero configuration needed.

---

## Features

### New Customer Grid Columns

1. **Total Orders Count** (Filterable)
   - Shows number of completed/processing orders per customer
   - Excludes canceled orders
   - Numeric filter (>, <, =)

2. **Lifetime Value (LTV)** (Filterable)
   - Sum of grand_total from all completed/processing orders
   - Formatted with base currency symbol
   - Numeric range filter

3. **Last Order Date** (Filterable)
   - Date of most recent completed/processing order
   - Date range filter (from/to)
   - Admin locale formatting

4. **Last Purchased Items**
   - Shows product names and quantities from latest order
   - Format: `iPhone 15 Pro ×1`
   - Max 3 items displayed, shows "+ X more" if exceeded
   - HTML-safe rendering

5. **Phone Number** (Searchable)
   - Merged from multiple sources:
     - Default billing address
     - Default shipping address
     - Most recent order address
   - Text search enabled

6. **Customer Type** (Filterable)
   - Registered: Has customer account
   - Guest: Orders exist but no account
   - Dropdown filter

7. **Email Domain** (Filterable)
   - Extracted domain from email (e.g., gmail.com)
   - Text filter enabled
   - Useful for B2B segmentation

---

## Technical Architecture

### Performance Optimization

✅ **Single optimized SQL query** with LEFT JOINs  
✅ **Subqueries for aggregations** (no N+1 query problem)  
✅ **Indexed fields** leveraged for filtering  
✅ **Lazy loading** for last purchased items  
✅ **No full order collection loading**

### SQL Strategy

The module uses a **Plugin on `Magento\Customer\Model\ResourceModel\Grid\Collection`** that injects optimized SQL:

```sql
-- Order statistics subquery (single query per page)
SELECT 
    customer_email,
    COUNT(*) as total_orders,
    SUM(grand_total) as lifetime_value,
    MAX(created_at) as last_order_date,
    SUBSTRING_INDEX(GROUP_CONCAT(entity_id ORDER BY created_at DESC), ",", 1) as last_order_id
FROM sales_order
WHERE status IN ('complete', 'processing')
GROUP BY customer_email

-- Phone number from addresses (optimized with COALESCE)
-- Email domain extraction (SUBSTRING_INDEX)
-- Customer type (CASE WHEN statement)
```

### File Structure

```
app/code/Thinkbeat/SmartCustomerGrid/
├── registration.php
├── etc/
│   ├── module.xml
│   └── di.xml
├── view/
│   └── adminhtml/
│       └── ui_component/
│           └── customer_listing.xml
├── Plugin/
│   └── Customer/
│       └── Model/
│           └── ResourceModel/
│               └── Grid/
│                   └── Collection.php
├── Ui/
│   └── Component/
│       └── Listing/
│           └── Column/
│               ├── LastPurchasedItems.php
│               ├── LifetimeValue.php
│               └── CustomerType.php
└── Model/
    └── Source/
        └── CustomerType.php
```

---

## Installation

### Step 1: Copy Module Files

```bash
cd /home/thinkbeat/Sites/React_app/premagento247
# Files are already in: app/code/Thinkbeat/SmartCustomerGrid/
```

### Step 2: Enable Module

```bash
php bin/magento module:enable Thinkbeat_SmartCustomerGrid
```

### Step 3: Run Setup Upgrade

```bash
php bin/magento setup:upgrade
```

### Step 4: Compile DI

```bash
php bin/magento setup:di:compile
```

### Step 5: Clear Cache

```bash
php bin/magento cache:clean
php bin/magento cache:flush
```

### Step 6: Set Permissions

```bash
sudo chown -R www-data:www-data pub/static/ var/ generated/
```

---

## Verification

### Check Module Status

```bash
php bin/magento module:status Thinkbeat_SmartCustomerGrid
```

**Expected Output:**
```
List of enabled modules:
Thinkbeat_SmartCustomerGrid
```

### Test in Admin

1. Log into Magento Admin
2. Navigate to: **Customers → All Customers**
3. Verify new columns appear:
   - Total Orders
   - Lifetime Value
   - Last Order Date
   - Last Purchased Items
   - Phone Number
   - Customer Type
   - Email Domain

4. Test filtering:
   - Filter by Total Orders > 5
   - Filter by Lifetime Value range
   - Filter by Customer Type = Registered
   - Search by Phone Number
   - Filter by Email Domain

---

## Usage Examples

### Filter High-Value Customers
- Set **Lifetime Value** filter to "> 1000"
- Set **Total Orders** filter to "> 3"

### Find Customers by Phone
- Use grid search with phone number
- Column is searchable via text input

### Segment by Email Domain
- Filter **Email Domain** = "company.com"
- Useful for B2B customer analysis

### View Recent Purchase Behavior
- Sort by **Last Order Date** descending
- Review **Last Purchased Items** for insights

---

## How Data is Fetched

### Order Data (Total Orders, LTV, Last Order Date)
- **Method:** Single LEFT JOIN with aggregated subquery
- **Performance:** O(1) query per grid page load
- **Filtering:** Efficient due to GROUP BY in subquery

### Last Purchased Items
- **Method:** Lazy loading via Order Repository
- **Performance:** Only fetches when column is visible
- **Caching:** In-memory cache per request

### Phone Number
- **Method:** COALESCE of 3 sources in single query
- **Priority:** Billing → Shipping → Order address
- **Performance:** No extra queries, pure SQL

### Email Domain
- **Method:** SQL SUBSTRING_INDEX function
- **Performance:** Zero overhead

### Customer Type
- **Method:** SQL CASE statement
- **Performance:** Computed in main query

---

## Troubleshooting

### Columns Not Appearing

```bash
# Clear all caches
php bin/magento cache:clean config layout block_html full_page

# Regenerate admin UI config
rm -rf var/view_preprocessed/* pub/static/adminhtml/*
php bin/magento setup:static-content:deploy -f
```

### Phone Numbers Missing

Check EAV attribute:
```bash
php bin/magento eav:attribute:list customer_address | grep telephone
```

### Performance Issues

Enable query logging:
```sql
-- In MySQL
SET GLOBAL general_log = 'ON';
SHOW VARIABLES LIKE 'general_log_file';
```

Review queries in customer grid page load.

---

## Uninstallation

```bash
php bin/magento module:disable Thinkbeat_SmartCustomerGrid
php bin/magento setup:upgrade
rm -rf app/code/Thinkbeat/SmartCustomerGrid
php bin/magento cache:flush
```

---

## Technical Notes

### Magento Coding Standards
- ✅ PSR-4 autoloading
- ✅ Strict types declared
- ✅ Dependency injection
- ✅ No deprecated methods
- ✅ No table prefix hardcoding

### Security
- ✅ HTML escaping for Last Purchased Items
- ✅ SQL injection prevention via parameterized queries
- ✅ ACL uses default Magento customer permissions

### Compatibility
- ✅ Works with standard Magento customer grid
- ✅ Compatible with third-party grid extensions (via plugin)
- ✅ No core file modifications
- ✅ Hyva theme compatible (admin only)

---

## Support

For issues or feature requests, contact your development team or review the code in:
```
app/code/Thinkbeat/SmartCustomerGrid/
```

---

## License

Copyright (c) 2026 Thinkbeat. All rights reserved.

---

## Changelog

### Version 1.0.0 (2026-01-29)
- Initial release
- 7 new customer grid columns
- Optimized SQL performance
- Full filtering support
- Zero configuration required
