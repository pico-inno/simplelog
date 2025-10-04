    <?php

use Illuminate\Database\Eloquent\Model;
use PicoInno\SimpleLog\Helpers\ActivityLogger;

if (! function_exists('activity')) {
    function activity($logName = null)
    {
        return app(ActivityLogger::class)
            ->name($logName);
    }

}

if (! function_exists('getRelationData')) {
    function getRelationData(Model $model, string $path, bool $old = false)
    {
        $value = $model;
        $segments = explode('.', $path);

        foreach ($segments as $i => $segment) {
            $isLast = ($i === count($segments) - 1);

            // ---- Last segment: get old attribute if requested ----
            if ($isLast && $old && $value instanceof Model) {
                $value = $value->getOriginal($segment, $value->$segment);

                // Auto-resolve old related model if foreign key
                if (str_ends_with($segment, '_id')) {
                    $relationName = substr($segment, 0, -3);
                    if (method_exists($value, $relationName)) {
                        $relatedModelClass = get_class($value->$relationName()->getRelated());
                        $value = $relatedModelClass::find($value->getOriginal($segment));
                    }
                }
            } else {
                // Traverse attribute or relation
                if (! isset($value->$segment)) {
                    if (method_exists($value, $segment)) {
                        $value = $value->$segment;
                    } else {
                        throw new Exception("Relation or attribute '{$segment}' does not exist!");
                    }
                } else {
                    $value = $value->$segment;
                }
            }

            // ---- Auto-pluck last attribute if collection ----
            if ($value instanceof Collection && $isLast) {
                $value = $value->pluck($segment)->toArray();
            } elseif ($value instanceof Collection) {
                $value = $value->toArray();
            }
        }

        return $value;
    }
}

if (! function_exists('get_relations_by_model')) {
    function get_relations_by_model(Model $model)
    {
        $relations = [];

        $methods = (new ReflectionClass($model))->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->class !== get_class($model)) {
                continue;
            }
            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            try {
                $return = $method->invoke($model);
                if ($return instanceof Relation) {
                    $relations[] = $method->getName();
                }
            } catch (\Throwable $e) {
            }
        }

        return $relations;
    }
}
