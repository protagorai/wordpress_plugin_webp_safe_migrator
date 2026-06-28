<?php
/**
 * Tiny reflection helper so integration tests can drive the plugin's private
 * methods/properties (commit, rollback, internal helpers) without changing
 * production visibility.
 */
class WebP_Reflect {

    public static function call($obj_or_class, string $method, array $args = []) {
        $class = is_object($obj_or_class) ? get_class($obj_or_class) : $obj_or_class;
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(is_object($obj_or_class) ? $obj_or_class : null, $args);
    }

    public static function get($obj_or_class, string $prop) {
        $class = is_object($obj_or_class) ? get_class($obj_or_class) : $obj_or_class;
        $ref = new ReflectionProperty($class, $prop);
        $ref->setAccessible(true);
        return $ref->getValue(is_object($obj_or_class) ? $obj_or_class : null);
    }

    public static function set($obj, string $prop, $value): void {
        $ref = new ReflectionProperty(get_class($obj), $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
