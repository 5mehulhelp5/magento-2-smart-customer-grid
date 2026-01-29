<?php
/**
 * Thinkbeat_SmartCustomerGrid Last Purchased Items Column
 *
 * Displays the items from customer's most recent order
 *
 * @category  Thinkbeat
 * @package   Thinkbeat_SmartCustomerGrid
 * @author    Thinkbeat
 * @copyright Copyright (c) 2026 Thinkbeat
 */

declare(strict_types=1);

namespace Thinkbeat\SmartCustomerGrid\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class LastPurchasedItems extends Column
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var ImageHelper
     */
    private $imageHelper;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var AssetRepository
     */
    private $assetRepo;

    /**
     * @var MediaConfig
     */
    private $mediaConfig;

    /**
     * @var FileDriver
     */
    private $fileDriver;

    /**
     * @var \Magento\Framework\Escaper
     */
    private $escaper;

    /**
     * @var array
     */
    private $orderItemsCache = [];

    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ImageHelper $imageHelper
     * @param ProductRepositoryInterface $productRepository
     * @param AssetRepository $assetRepository
     * @param MediaConfig $mediaConfig
     * @param FileDriver $fileDriver
     * @param \Magento\Framework\Escaper $escaper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ImageHelper $imageHelper,
        ProductRepositoryInterface $productRepository,
        AssetRepository $assetRepository,
        MediaConfig $mediaConfig,
        FileDriver $fileDriver,
        \Magento\Framework\Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->imageHelper = $imageHelper;
        $this->productRepository = $productRepository;
        $this->assetRepo = $assetRepository;
        $this->mediaConfig = $mediaConfig;
        $this->fileDriver = $fileDriver;
        $this->escaper = $escaper;
    }

    /**
     * Prepare data source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (isset($item['last_order_id']) && $item['last_order_id']) {
                $item[$this->getData('name')] = $this->getLastPurchasedItems((int)$item['last_order_id']);
            } else {
                $item[$this->getData('name')] = '<span style="color: #999;">No orders</span>';
            }
        }

        return $dataSource;
    }

    /**
     * Get formatted last purchased items
     *
     * @param int $orderId
     * @return string
     */
    private function getLastPurchasedItems(int $orderId): string
    {
        if (isset($this->orderItemsCache[$orderId])) {
            return $this->orderItemsCache[$orderId];
        }

        try {
            $order = $this->orderRepository->get($orderId);
            $items = $order->getAllVisibleItems();

            if (empty($items)) {
                $result = '<span style="color: #999;">No items</span>';
                $this->orderItemsCache[$orderId] = $result;
                return $result;
            }

            $previewItems = [];
            $allItems = [];
            $maxPreview = 3;
            $count = 0;

            foreach ($items as $item) {
                $name = $this->escaper->escapeHtml($item->getName());
                $qty = (int)$item->getQtyOrdered();
                $imageUrl = $this->getProductImageUrl($item);
                
                $itemHtml = sprintf(
                    '<div class="item-row" style="margin-bottom: 4px; display: flex; align-items: center;">' .
                    '<img src="%s" alt="%s" style="width: 30px; height: 30px; object-fit: cover; ' .
                    'margin-right: 8px; border: 1px solid #ddd; border-radius: 2px;">' .
                    '<span>%s ×%d</span>' .
                    '</div>',
                    $imageUrl,
                    $name,
                    $name,
                    $qty
                );

                $allItems[] = $itemHtml;

                if ($count < $maxPreview) {
                    $previewItems[] = $itemHtml;
                }
                $count++;
            }

            $result = implode('', $previewItems);

            if (count($items) > $maxPreview) {
                $remaining = count($items) - $maxPreview;
                $modalId = 'last-purchased-modal-' . $orderId;
                
                // Button to trigger modal
                $result .= sprintf(
                    '<div style="margin-top: 5px;"><a href="#" onclick="event.stopPropagation(); ' .
                    'require([\'jquery\', \'Magento_Ui/js/modal/modal\'], function($, modal) { ' .
                    'var modalEl = $(\'#%s\'); var options = {type: \'popup\', responsive: true, innerScroll: true, ' .
                    'title: \'Last Purchased Items (Order #%s)\', buttons: [{text: \'Close\', click: function() ' .
                    '{ modalEl.modal(\'closeModal\'); }}] }; modal(options, modalEl); modalEl.modal(\'openModal\'); ' .
                    '}); return false;">+ %d more</a></div>',
                    $modalId,
                    $order->getIncrementId(),
                    $remaining
                );

                // Hidden Modal Content
                $result .= sprintf(
                    '<div id="%s" style="display:none;">%s</div>',
                    $modalId,
                    implode('', $allItems)
                );
            }

            $this->orderItemsCache[$orderId] = $result;
            return $result;

        } catch (\Exception $e) {
            $result = '<span style="color: #c33;">Error loading items</span>';
            $this->orderItemsCache[$orderId] = $result;
            return $result;
        }
    }

    /**
     * Get Product Image URL
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     * @return string
     */
    private function getProductImageUrl($item): string
    {
        try {
            $product = null;

            // Try by product ID first
            $productId = $item->getProductId();
            if ($productId) {
                try {
                    $product = $this->productRepository->getById($productId);
                } catch (NoSuchEntityException $e) {
                    $product = null; // Indicates product not found
                }
            }

            // If no product by ID, try by SKU
            if (!$product) {
                $sku = $item->getSku();
                if (!empty($sku)) {
                    try {
                        $product = $this->productRepository->get($sku);
                    } catch (NoSuchEntityException $e) {
                        $product = null; // Indicates product not found
                    }
                }
            }

            if ($product) {
                $image = $this->findProductImage($product);
                
                if ($image) {
                    // 1. Try to get resized image via Helper
                    $helperUrl = $this->getImageHelperUrl($product, $image);
                    if (!$this->isPlaceholder($helperUrl)) {
                        return $helperUrl;
                    }

                    // 2. Fallback: Return original media URL directly
                    // This avoids issues where cache generation fails but image exists
                    try {
                        return $this->mediaConfig->getMediaUrl($image);
                    } catch (\Exception $e) {
                        return '';
                    }
                }
            }

            return $this->imageHelper->getDefaultPlaceholderUrl('small_image');
        } catch (\Exception $e) {
            return $this->imageHelper->getDefaultPlaceholderUrl('small_image') ?: '';
        }
    }

    /**
     * Find best available image path from product
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string|null
     */
    private function findProductImage($product)
    {
        // 1. Check standard attributes
        $image = $product->getSmallImage();
        if ($this->isValidImage($image)) {
            return $image;
        }

        $image = $product->getThumbnail();
        if ($this->isValidImage($image)) {
            return $image;
        }

        $image = $product->getImage();
        if ($this->isValidImage($image)) {
            return $image;
        }

        // 2. Check Gallery
        // Force load gallery if not loaded
        if (!$product->hasData('media_gallery_images')) {
            $product->load($product->getId());
        }
        
        $gallery = $product->getMediaGalleryImages();
        if ($gallery) {
            foreach ($gallery as $galItem) {
                $file = $galItem->getFile();
                if ($this->isValidImage($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Check if image path is valid
     *
     * @param string|null $image
     * @return bool
     */
    private function isValidImage($image)
    {
        return $image && $image !== 'no_selection';
    }

    /**
     * Get Helper URL
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string $imageFile
     * @return string
     */
    private function getImageHelperUrl($product, $imageFile)
    {
        try {
            $helper = $this->imageHelper->init($product, 'product_listing_thumbnail');
            $helper->setImageFile($imageFile);
            return $helper->resize(50)->getUrl();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if URL looks like a placeholder
     *
     * @param string $url
     * @return bool
     */
    private function isPlaceholder($url)
    {
        if (empty($url)) {
            return true;
        }
        return strpos($url, 'placeholder') !== false;
    }
}
