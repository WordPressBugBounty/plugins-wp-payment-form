<?php

namespace WPPayForm\Framework\Database\Orm\Relations\Concerns;

use WPPayForm\Framework\Database\Orm\Model;
use WPPayForm\Framework\Database\Orm\SupportsPartialRelations;

trait ComparesRelatedModels
{
    /**
     * Determine if the model is the related instance of the relationship.
     *
     * @param  \WPPayForm\Framework\Database\Orm\Model|null  $model
     * @return bool
     */
    public function is($model)
    {
        $match = ! is_null($model) &&
               $this->compareKeys($this->getParentKey(), $this->getRelatedKeyFrom($model)) &&
               $this->related->getTable() === $model->getTable() &&
               $this->related->getConnectionName() === $model->getConnectionName();

        if ($match && $this instanceof SupportsPartialRelations && $this->isOneOfMany()) {
            return $this->query
                        ->whereKey($model->getKey())
                        ->exists();
        }

        return $match;
    }

    /**
     * Determine if the model is not the related instance of the relationship.
     *
     * @param  \WPPayForm\Framework\Database\Orm\Model|null  $model
     * @return bool
     */
    public function isNot($model)
    {
        return ! $this->is($model);
    }

    /**
     * Get the value of the parent model's key.
     *
     * @return mixed
     */
    abstract public function getParentKey();

    /**
     * Get the value of the model's related key.
     *
     * @param  \WPPayForm\Framework\Database\Orm\Model  $model
     * @return mixed
     */
    abstract protected function getRelatedKeyFrom(Model $model);

    /**
     * Compare the parent key with the related key.
     *
     * @param  mixed  $parentKey
     * @param  mixed  $relatedKey
     * @return bool
     */
    protected function compareKeys($parentKey, $relatedKey)
    {
        if (empty($parentKey) || empty($relatedKey)) {
            return false;
        }

        if (is_int($parentKey) || is_int($relatedKey)) {
            return (int) $parentKey === (int) $relatedKey;
        }

        return $parentKey === $relatedKey;
    }
}