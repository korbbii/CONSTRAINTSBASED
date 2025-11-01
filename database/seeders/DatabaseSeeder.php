<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Room;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create sample rooms
        $this->createSampleRooms();
    }

    private function createSampleRooms(): void
    {
        $rooms = [
            // Annex Building - Floor 1
            ['room_name' => 'ANNEX 101', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 102', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 103', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 104', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 105', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 106', 'building' => 'Annex Building', 'floor_level' => 1, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            
            // Annex Building - Floor 2
            ['room_name' => 'ANNEX 208', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 50, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 207', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 209', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 204', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 203', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 202', 'building' => 'Annex Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            
            // Annex Building - Floor 3
            ['room_name' => 'ANNEX 301', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 302', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 303', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 304', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 305', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 306', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 307', 'building' => 'Annex Building', 'floor_level' => 3, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            
            // Annex Building - Floor 4
            ['room_name' => 'ANNEX 407', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 50, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 406', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 405', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 404', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 403', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 402', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'ANNEX 401', 'building' => 'Annex Building', 'floor_level' => 4, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            
            // SHS Building - Floor 1
            ['room_name' => 'SHS 109', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SHS 111', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SHS 108', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SHS 107', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 25, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SSH 112', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            
            // SHS Building - Floor 2
            ['room_name' => 'SHS 205', 'building' => 'SHS Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SHS 206', 'building' => 'SHS Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'SHS 209', 'building' => 'SHS Building', 'floor_level' => 2, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            
            // HS Building - Floor 2
            ['room_name' => 'HS 215', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 214', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 210', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 209', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 208', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 206', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 205', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 204', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 203', 'building' => 'HS Building', 'floor_level' => 2, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            
            // HS Building - Floor 3
            ['room_name' => 'HS 309', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 310', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 308', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 307', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 45, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 306', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 311', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 40, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 312', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 30, 'is_lab' => false, 'is_active' => true],
            ['room_name' => 'HS 313', 'building' => 'HS Building', 'floor_level' => 3, 'capacity' => 35, 'is_lab' => false, 'is_active' => true],
            
            // Laboratory Rooms
            ['room_name' => 'LAB 1', 'building' => 'Laboratory Building', 'floor_level' => 1, 'capacity' => 25, 'is_lab' => true, 'is_active' => true],
            ['room_name' => 'LAB 2', 'building' => 'Laboratory Building', 'floor_level' => 1, 'capacity' => 30, 'is_lab' => true, 'is_active' => true],
            ['room_name' => 'HS Lab', 'building' => 'HS Building', 'floor_level' => 1, 'capacity' => 20, 'is_lab' => true, 'is_active' => true],
            ['room_name' => 'SHS Lab', 'building' => 'SHS Building', 'floor_level' => 1, 'capacity' => 25, 'is_lab' => true, 'is_active' => true],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(
                ['room_name' => $room['room_name']],
                $room
            );
        }
    }
}
