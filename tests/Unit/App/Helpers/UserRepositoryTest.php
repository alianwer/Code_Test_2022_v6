<?php

use DTApi\Repository\UserRepository;
use DTApi\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class UserRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    protected $userRepository;

    public function setUp(): void
    {
        parent::setUp();
        $this->userRepository = new UserRepository(new User);
    }

    /** @test */
    public function it_creates_or_updates_user()
    {
        // Create a new user
        $userData = [
            'role' => 'customer',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            // Add other required fields here
        ];

        $user = $this->userRepository->createOrUpdate(null, $userData);

        // Assert
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($userData['role'], $user->user_type);
        $this->assertEquals($userData['name'], $user->name);
        // Add other assertions based on the fields you set in the createOrUpdate function

        //Add other assertions as required for that function

    }
}
