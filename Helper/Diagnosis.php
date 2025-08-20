<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

class Diagnosis extends AbstractHelper
{
    private $moduleManager;
    private $logger;
    private $appState;
    
    public function __construct(
        Context $context,
        ModuleManager $moduleManager,
        LoggerInterface $logger,
        State $appState
    ) {
        parent::__construct($context);
        $this->moduleManager = $moduleManager;
        $this->logger = $logger;
        $this->appState = $appState;
    }
    
    public function runFullDiagnosis(): array
    {
        $diagnosis = [
            'timestamp' => date('Y-m-d H:i:s'),
            'module_status' => $this->checkModuleStatus(),
            'routes_status' => $this->checkRoutesStatus(),
            'acl_status' => $this->checkAclStatus(),
            'dependencies' => $this->checkDependencies(),
            'file_permissions' => $this->checkFilePermissions(),
            'cache_status' => $this->checkCacheStatus()
        ];
        
        $this->logger->info('EasYorder Full Diagnosis', $diagnosis);
        return $diagnosis;
    }
    
    private function checkModuleStatus(): array
    {
        return [
            'enabled' => $this->moduleManager->isEnabled('MagoArab_EasYorder'),
            'output_enabled' => $this->moduleManager->isOutputEnabled('MagoArab_EasYorder')
        ];
    }
    
    private function checkRoutesStatus(): array
    {
        $routes = [
            'admin_routes' => [
                'easyorder/checkout_fields/index',
                'easyorder/order_attributes/index', 
                'easyorder/customer_attributes/index',
                'easyorder/diagnosis/index'
            ],
            'frontend_routes' => [
                'easyorder/order/create'
            ]
        ];
        
        return $routes;
    }
    
    private function checkAclStatus(): array
    {
        return [
            'resources' => [
                'MagoArab_EasYorder::easyorder',
                'MagoArab_EasYorder::easyorder_checkout_fields',
                'MagoArab_EasYorder::easyorder_order_attributes',
                'MagoArab_EasYorder::easyorder_customer_attributes'
            ]
        ];
    }
    
    private function checkDependencies(): array
    {
        $requiredModules = [
            'Magento_Catalog',
            'Magento_Quote', 
            'Magento_Sales',
            'Magento_Customer',
            'Magento_Store',
            'Magento_Backend'
        ];
        
        $status = [];
        foreach ($requiredModules as $module) {
            $status[$module] = $this->moduleManager->isEnabled($module);
        }
        
        return $status;
    }
    
    private function checkFilePermissions(): array
    {
        $files = [
            'registration.php',
            'etc/module.xml',
            'etc/adminhtml/routes.xml',
            'etc/acl.xml'
        ];
        
        $status = [];
        foreach ($files as $file) {
            $fullPath = __DIR__ . '/../' . $file;
            $status[$file] = [
                'exists' => file_exists($fullPath),
                'readable' => is_readable($fullPath)
            ];
        }
        
        return $status;
    }
    
    private function checkCacheStatus(): array
    {
        try {
            $mode = $this->appState->getMode();
            return [
                'app_mode' => $mode,
                'needs_cache_clear' => $mode === State::MODE_PRODUCTION
            ];
        } catch (LocalizedException $e) {
            return [
                'app_mode' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }
}