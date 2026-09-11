<?php

function shared_course_ids(array $courseIdsA, array $courseIdsB): array
{
    return array_values(array_intersect($courseIdsA, $courseIdsB));
}
