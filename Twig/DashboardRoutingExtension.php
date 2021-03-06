<?php
declare(strict_types=1);

namespace Jadob\Dashboard\Twig;

use Jadob\Dashboard\PathGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DashboardRoutingExtension extends AbstractExtension
{
    protected PathGenerator $pathGenerator;

    public function __construct(PathGenerator $pathGenerator)
    {
        $this->pathGenerator = $pathGenerator;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_path_object_list', [$this->pathGenerator, 'getPathForObjectList']),
            new TwigFunction('dashboard_path_object_show', [$this->pathGenerator, 'getPathForObjectShow']),
            new TwigFunction('dashboard_path_object_new', [$this->pathGenerator, 'getPathForObjectNew']),
            new TwigFunction('dashboard_path_object_edit', [$this->pathGenerator, 'getPathForObjectEdit']),
            new TwigFunction('dashboard_path_object_import', [$this->pathGenerator, 'getPathForImport']),
            new TwigFunction('dashboard_path_object_operation', [$this->pathGenerator, 'getPathForObjectOperation']),
            new TwigFunction('dashboard_path_object_redirect', [$this->pathGenerator, 'getPathForObjectRedirect']),
            new TwigFunction('dashboard_path_batch_object_operation', [$this->pathGenerator, 'getPathForBatchOperation'])
        ];
    }
}