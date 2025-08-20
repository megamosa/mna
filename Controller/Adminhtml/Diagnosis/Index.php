<?php
declare(strict_types=1);

namespace MagoArab\EasYorder\Controller\Adminhtml\Diagnosis;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use MagoArab\EasYorder\Helper\Diagnosis as DiagnosisHelper;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Index extends Action
{
    private $resultPageFactory;
    private $diagnosisHelper;
    private $logger;
    private $jsonFactory;
    
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        DiagnosisHelper $diagnosisHelper,
        LoggerInterface $logger,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->diagnosisHelper = $diagnosisHelper;
        $this->logger = $logger;
        $this->jsonFactory = $jsonFactory;
    }
    
    public function execute()
    {
        try {
            // Run comprehensive diagnosis
            $diagnosis = $this->diagnosisHelper->runFullDiagnosis();
            
            // Log detailed results
            $this->logger->info('EasYorder Comprehensive Diagnosis', $diagnosis);
            
            // Check for critical issues
            $this->processDiagnosisResults($diagnosis);
            
            // If AJAX request, return JSON
            if ($this->getRequest()->isAjax()) {
                $result = $this->jsonFactory->create();
                return $result->setData($diagnosis);
            }
            
            // Create page result
            $resultPage = $this->resultPageFactory->create();
            $resultPage->setActiveMenu('MagoArab_EasYorder::easyorder');
            $resultPage->addBreadcrumb(__('EasyOrder'), __('EasyOrder'));
            $resultPage->addBreadcrumb(__('Diagnosis'), __('Diagnosis'));
            $resultPage->getConfig()->getTitle()->prepend(__('EasyOrder - System Diagnosis'));
            
            return $resultPage;
            
        } catch (\Exception $e) {
            $this->logger->critical('EasYorder Diagnosis Error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->messageManager->addErrorMessage(
                'Diagnosis failed: ' . $e->getMessage()
            );
            
            return $this->resultRedirectFactory->create()->setPath('admin/dashboard');
        }
    }
    
    private function processDiagnosisResults(array $diagnosis): void
    {
        // Check module status
        if (!$diagnosis['module_status']['enabled']) {
            $this->messageManager->addErrorMessage(
                'EasYorder module is DISABLED! Run: php bin/magento module:enable MagoArab_EasYorder'
            );
        }
        
        if (!$diagnosis['module_status']['output_enabled']) {
            $this->messageManager->addErrorMessage(
                'EasYorder module output is DISABLED!'
            );
        }
        
        // Check dependencies
        foreach ($diagnosis['dependencies'] as $module => $enabled) {
            if (!$enabled) {
                $this->messageManager->addErrorMessage(
                    "Required module {$module} is disabled!"
                );
            }
        }
        
        // Check file permissions
        foreach ($diagnosis['file_permissions'] as $file => $status) {
            if (!$status['exists']) {
                $this->messageManager->addErrorMessage(
                    "Critical file missing: {$file}"
                );
            } elseif (!$status['readable']) {
                $this->messageManager->addErrorMessage(
                    "File not readable: {$file}"
                );
            }
        }
        
        // Cache recommendations
        if ($diagnosis['cache_status']['needs_cache_clear']) {
            $this->messageManager->addNoticeMessage(
                'Production mode detected. Run: php bin/magento cache:flush'
            );
        }
        
        $this->messageManager->addSuccessMessage(
            'Diagnosis completed. Check system.log for detailed results.'
        );
    }
    
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MagoArab_EasYorder::easyorder');
    }
}