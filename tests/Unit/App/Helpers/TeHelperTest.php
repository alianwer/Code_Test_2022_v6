<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use DTApi\Helpers\TeHelper;

class TeHelperTest extends TestCase
{
    public function testWillExpireAt()
    {
        // Mock input data
        $dueTime = '2024-01-07 12:00:00';
        $createdAt = '2024-01-01 08:00:00';

        // Call the method
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        // Convert the result and expected values to Carbon objects for easy comparison
        $resultCarbon = Carbon::parse($result);
        $expectedCarbon = Carbon::parse('2024-01-05 00:00:00');

        // Assert that the result is equal to the expected value
        $this->assertEquals($expectedCarbon, $resultCarbon);
    }
}
