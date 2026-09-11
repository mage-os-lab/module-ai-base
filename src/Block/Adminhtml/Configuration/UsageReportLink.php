<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Configuration;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The one field in the Usage Tracking group that is not a setting: a link to the report the
 * settings are about.
 *
 * A `frontend_model` block rather than a `<comment>` because an admin URL carries a per-session
 * secret key that only the URL builder can supply; a static href in `system.xml` would land the
 * administrator on the dashboard with a "invalid security key" notice. Rendered as a plain link
 * rather than a button: it navigates, it does not act.
 *
 * The link is offered only to a role that may open the report. ACL is the permission boundary
 * (the page's own controller enforces it), so this is not what keeps anyone out; it is what keeps
 * the configuration page from advertising something the reader would be refused.
 */
class UsageReportLink extends Field
{
    /**
     * The route of the usage report, as `etc/adminhtml/routes.xml` and the usage menu item name it.
     */
    public const REPORT_ROUTE = 'mageos_ai/usage/index';

    /**
     * The ACL resource the report's controller checks, so the link is only shown to a role that
     * would get past it.
     */
    public const REPORT_ACL_RESOURCE = 'MageOS_AiBase::usage';

    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->getReportLinkHtml();
    }

    /**
     * The link itself, or a sentence explaining its absence to a role that may not follow it.
     *
     * Public so the rendering can be asserted without building a form element, which needs the
     * form element factory and a collection factory this block otherwise never touches.
     *
     * @return string
     */
    public function getReportLinkHtml(): string
    {
        if (!$this->_authorization->isAllowed(self::REPORT_ACL_RESOURCE)) {
            return sprintf(
                '<p class="note">%s</p>',
                $this->_escaper->escapeHtml(__('The usage report is under Reports, for roles allowed to see it.'))
            );
        }

        return sprintf(
            '<a class="mageos-ai-usage-report-link" href="%s">%s</a>',
            $this->_escaper->escapeHtml($this->getUrl(self::REPORT_ROUTE)),
            $this->_escaper->escapeHtml(__('Open the AI Token Usage report'))
        );
    }
}
