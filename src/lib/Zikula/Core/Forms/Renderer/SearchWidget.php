<?php

namespace Zikula\Core\Forms\Renderer;

use Symfony\Component\Form\FormView;
use Zikula\Core\Forms\RendererInterface;
use Zikula\Core\Forms\FormRenderer;

/**
 *
 */
class SearchWidget implements RendererInterface
{
    public function getName()
    {
        return 'search_widget';
    }
    
    public function render(FormView $form, $variables, FormRenderer $renderer)
    {
        if(!isset($variables['type'])) {
            $variables['type'] = 'search';
        }
        
        return $renderer->getRender('field_widget')->render($form, $variables, $renderer);
    }
}
