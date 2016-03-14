<?php

namespace SensioLabs\Deptrac\OutputFormatter;

use SensioLabs\Deptrac\DependencyContext;
use SensioLabs\Deptrac\ClassNameLayerResolverInterface;
use SensioLabs\Deptrac\DependencyResult;
use SensioLabs\Deptrac\RulesetEngine\RulesetViolation;
use Fhaculty\Graph\Vertex;
use SensioLabs\AstRunner\AstMap;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class GraphVizOutputFormatter implements OutputFormatterInterface
{
    protected $eventDispatcher;

    private static $argument_display = 'display';

    private static $argument_dump_image = 'dump-image';

    private static $argument_dump_dot = 'dump-dot';

    private static $argument_dump_html = 'dump-html';

    public function getName()
    {
        return 'graphviz';
    }

    /**
     * @return OutputFormatterOption[]
     */
    public function configureOptions()
    {
        return [
            OutputFormatterOption::newValueOption(static::$argument_display, 'gib mir foo1', 'bar1'),
            OutputFormatterOption::newValueOption(static::$argument_dump_image, 'gib mir foo1', 'bar1'),
            OutputFormatterOption::newValueOption(static::$argument_dump_dot, 'gib mir foo1', 'bar1'),
            OutputFormatterOption::newValueOption(static::$argument_dump_html, 'gib mir foo1', 'bar1')
        ];
    }


    /**
     * @param DependencyContext $dependencyContext
     * @param OutputInterface $output
     * @param OutputFormatterInput $outputFormatterInput
     */
    public function finish(
        DependencyContext $dependencyContext,
        OutputInterface $output,
        OutputFormatterInput $outputFormatterInput
    ) {

        $layerViolations = $this->calculateViolations($dependencyContext->getViolations());

        $layersDependOnLayers = $this->calculateLayerDependencies(
            $dependencyContext->getAstMap(),
            $dependencyContext->getDependencyResult(),
            $dependencyContext->getClassNameLayerResolver()
        );

        $graph = new \Fhaculty\Graph\Graph();

        /** @var $vertices Vertex[] */
        $vertices = [];

        // create a vertices
        foreach ($layersDependOnLayers as $layer => $layersDependOn) {
            if (!isset($vertices[$layer])) {
                $vertices[$layer] = $graph->createVertex($layer);
            }

            foreach ($layersDependOn as $layerDependOn => $layerDependOnCount) {
                if (!isset($vertices[$layerDependOn])) {
                    $vertices[$layerDependOn] = $graph->createVertex($layerDependOn);
                }
            }
        }

        // createEdges
        foreach ($layersDependOnLayers as $layer => $layersDependOn) {
            foreach ($layersDependOn as $layerDependOn => $layerDependOnCount) {
                $vertices[$layer]->createEdgeTo($vertices[$layerDependOn]);

                if (isset($layerViolations[$layer], $layerViolations[$layer][$layerDependOn])) {
                    $edge = $vertices[$layer]->getEdgesTo($vertices[$layerDependOn])->getEdgeFirst();
                    $edge->setAttribute('graphviz.label', $layerViolations[$layer][$layerDependOn]);
                    $edge->setAttribute('graphviz.color', 'red');
                }
            }
        }

        if ($outputFormatterInput->getOption(static::$argument_display, 1)) {
            (new \Graphp\GraphViz\GraphViz())->display($graph);
        }

        if ($dumpImagePath = $outputFormatterInput->getOption(static::$argument_dump_image, 0)) {
            $imagePath = (new \Graphp\GraphViz\GraphViz())->createImageFile($graph);
            rename($imagePath, $dumpImagePath);
            $output->write('<info>Image dumped to '.realpath($dumpImagePath).'</info>');
        }

        if ($dumpDotPath = $outputFormatterInput->getOption(static::$argument_dump_dot, 0)) {
            file_put_contents($dumpDotPath, (new \Graphp\GraphViz\GraphViz())->createScript($graph));
        }

        if ($dumpHtmlPath = $outputFormatterInput->getOption(static::$argument_dump_html, 0)) {
            file_put_contents($dumpHtmlPath, (new \Graphp\GraphViz\GraphViz())->createImageHtml($graph));
        }

    }

    /**
     * @param RulesetViolation[] $violations
     * @return array
     */
    private function calculateViolations(array $violations)
    {
        $layerViolations = [];
        foreach ($violations as $violation) {
            if (!isset($layerViolations[$violation->getLayerA()])) {
                $layerViolations[$violation->getLayerA()] = [];
            }

            if (!isset($layerViolations[$violation->getLayerA()][$violation->getLayerB()])) {
                $layerViolations[$violation->getLayerA()][$violation->getLayerB()] = 1;
            } else {
                $layerViolations[$violation->getLayerA()][$violation->getLayerB(
                )] = $layerViolations[$violation->getLayerA()][$violation->getLayerB()] + 1;
            }
        }

        return $layerViolations;
    }

    /**
     * @param AstMap $astMap
     * @param DependencyResult $dependencyResult
     * @param ClassNameLayerResolverInterface $classNameLayerResolver
     * @return array
     */
    private function calculateLayerDependencies(
        AstMap $astMap,
        DependencyResult $dependencyResult,
        ClassNameLayerResolverInterface $classNameLayerResolver
    ) {
        $layersDependOnLayers = [];

        // all classes
        foreach ($astMap->getAstClassReferences() as $classReferences) {
            foreach ($classNameLayerResolver->getLayersByClassName(
                $classReferences->getClassName()
            ) as $classReferenceLayer) {
                $layersDependOnLayers[$classReferenceLayer] = [];
            }
        }

        // dependencies
        foreach ($dependencyResult->getDependenciesAndInheritDependencies() as $dependency) {
            $layersA = $classNameLayerResolver->getLayersByClassName($dependency->getClassA());
            $layersB = $classNameLayerResolver->getLayersByClassName($dependency->getClassB());

            if (empty($layersB)) {
                continue;
            }

            foreach ($layersA as $layerA) {
                if (!isset($layersDependOnLayers[$layerA])) {
                    $layersDependOnLayers[$layerA] = [];
                }

                foreach ($layersB as $layerB) {
                    if ($layerA == $layerB) {
                        continue;
                    }

                    if (!isset($layersDependOnLayers[$layerA][$layerB])) {
                        $layersDependOnLayers[$layerA][$layerB] = 1;
                        continue;
                    }

                    $layersDependOnLayers[$layerA][$layerB] = $layersDependOnLayers[$layerA][$layerB] + 1;
                }

            }
        }

        return $layersDependOnLayers;
    }
}
