<?php

namespace App\Support;

final class PhilippineLand
{
    public const MESSAGE = 'Choose a location on land within the Philippines, not on water or in another country.';

    public function contains(float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < 4 || $latitude > 22 || $longitude < 116 || $longitude > 128) {
            return false;
        }

        static $data;
        $data ??= json_decode(file_get_contents(public_path('maps/philippines-land-v1.json')), true, 512, JSON_THROW_ON_ERROR);

        return $this->inPolygons($data['land'], $longitude, $latitude)
            && ! $this->inPolygons($data['ponds'], $longitude, $latitude)
            && (! $this->inPolygons($data['water'], $longitude, $latitude)
                || $this->inPolygons($data['lakeIslands'], $longitude, $latitude));
    }

    private function inPolygons(array $polygons, float $x, float $y): bool
    {
        foreach ($polygons as $polygon) {
            [$west, $south, $east, $north] = $polygon['bounds'];
            if ($x < $west || $x > $east || $y < $south || $y > $north) {
                continue;
            }
            $inside = false;
            foreach ($polygon['rings'] as $ring) {
                // Even-odd fill also excludes any holes in the land boundary.
                $previous = null;
                foreach ($this->points($ring) as [$xi, $yi]) {
                    if ($previous === null) {
                        $previous = [$xi, $yi];

                        continue;
                    }
                    [$xj, $yj] = $previous;
                    if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                        $inside = ! $inside;
                    }
                    $previous = [$xi, $yi];
                }
            }
            if ($inside) {
                return true;
            }
        }

        return false;
    }

    private function points(string $encoded): \Generator
    {
        // Decode only a candidate polygon, keeping PHP memory use bounded.
        $position = [0, 0];
        for ($i = 0, $length = strlen($encoded); $i < $length;) {
            foreach ([0, 1] as $axis) {
                $value = 0;
                $shift = 0;
                do {
                    $byte = ord($encoded[$i++]) - 63;
                    $value |= ($byte & 31) << $shift;
                    $shift += 5;
                } while ($byte >= 32);
                $position[$axis] += ($value & 1) ? ~($value >> 1) : ($value >> 1);
            }
            yield [$position[0] / 1e6, $position[1] / 1e6];
        }
    }
}
