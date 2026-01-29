<?php
/**
 * Thinkbeat_SmartCustomerGrid Customer Type Source Model
 *
 * Provides options for customer type filter dropdown
 *
 * @category  Thinkbeat
 * @package   Thinkbeat_SmartCustomerGrid
 * @author    Thinkbeat
 * @copyright Copyright (c) 2026 Thinkbeat
 */

declare(strict_types=1);

namespace Thinkbeat\SmartCustomerGrid\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CustomerType implements OptionSourceInterface
{
    /**
     * Get options for customer type filter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'registered', 'label' => __('Registered')],
            ['value' => 'guest', 'label' => __('Guest')]
        ];
    }
}
