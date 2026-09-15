%5+1
%5+1
<?php

namespace FluentForm\App\Modules\Ai;

/**
 *  Handling Ai Module.
 *
 * @since 6.0.0
 */
class AiController
{

    public function boot()
    {
        new AiFormBuilder();
    }
}
