<?php
// /home/bot.dailymu.com/private/src/UserHandler.php
require_once __DIR__ . '/CacheHandler.php';

class UserHandler {
    private $db;
    private $cache;

    public function __construct() {
        $this->db = DatabaseHandler::getInstance();
        $this->cache = new CacheHandler();
    }

    public function getOrCreateUser($platform, $platformId) {
        $cacheKey = "user_{$platform}_{$platformId}";
        $cachedUser = $this->cache->get($cacheKey);
        
        if ($cachedUser) {
            return $cachedUser;
        }

        try {
            return $this->db->transaction(function($db) use ($platform, $platformId, $cacheKey) {
                // ค้นหา user ที่มีอยู่
                $user = $db->query(
                    "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                    [$platform, $platformId]
                )->fetch();

                if (!$user) {
                    // สร้าง user ใหม่
                    $db->query(
                        "INSERT INTO users (platform, platform_id) VALUES (?, ?)",
                        [$platform, $platformId]
                    );
                    
                    $user = $db->query(
                        "SELECT * FROM users WHERE platform = ? AND platform_id = ?",
                        [$platform, $platformId]
                    )->fetch();
                }

                // อัพเดท last_visit
                $db->query(
                    "UPDATE users SET last_visit = CURRENT_TIMESTAMP WHERE id = ?",
                    [$user['id']]
                );

                // เก็บใน cache
                $this->cache->set($cacheKey, $user);

                return $user;
            });

        } catch (Exception $e) {
            error_log("Error in getOrCreateUser: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateUser($userId, $data) {
        $allowedFields = ['nickname', 'birth_date', 'zodiac'];
        $updates = [];
        $values = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        try {
            $values[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $updates) . 
                   " WHERE id = ?";

            $this->db->query($sql, $values);

            // ดึงข้อมูล user ที่อัพเดทแล้ว
            $user = $this->getUserById($userId);
            if ($user) {
                // ล้าง cache
                $cacheKey = "user_{$user['platform']}_{$user['platform_id']}";
                $this->cache->delete($cacheKey);
            }

            return true;

        } catch (Exception $e) {
            error_log("Error in updateUser: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ดึงข้อมูล User Profile แบบสมบูรณ์
     */
    public function getUserProfile($userId) {
        try {
            $sql = "SELECT 
                        u.*,
                        (SELECT 
                            JSON_OBJECTAGG(t.tag_key, t.tag_value)
                         FROM user_tags t 
                         WHERE t.user_id = u.id
                        ) as tags,
                        (SELECT 
                            COUNT(*)
                         FROM fortune_history f 
                         WHERE f.user_id = u.id
                        ) as fortune_count,
                        (SELECT 
                            created_at
                         FROM fortune_history f 
                         WHERE f.user_id = u.id 
                         ORDER BY created_at DESC 
                         LIMIT 1
                        ) as last_fortune_date
                    FROM users u
                    WHERE u.id = ?";

            return $this->db->query($sql, [$userId])->fetch();
        } catch (Exception $e) {
            error_log("Error in getUserProfile: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ตรวจสอบและอัพเดทข้อมูลจากบทสนทนา
     */
    public function processConversation($userId, $message) {
        // ตรวจจับชื่อเล่น
        if (preg_match('/ชื่อ(?:ว่า)?[\s:]*([\wก-๙]+)/u', $message, $matches)) {
            $this->updateUser($userId, ['nickname' => $matches[1]]);
        }

        // ตรวจจับวันเกิด
        if (preg_match('/เกิดวันที่[\s:]*([\d]{1,2})[\s\/\-]([\d]{1,2})(?:[\s\/\-]([\d]{4}))?/u', $message, $matches)) {
            $day = $matches[1];
            $month = $matches[2];
            $year = isset($matches[3]) ? $matches[3] : date('Y');
            if ($year < 2400) { // แปลง พ.ศ. เป็น ค.ศ.
                $year -= 543;
            }
            $birth_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $this->updateUser($userId, ['birth_date' => $birth_date]);
            
            // คำนวณราศี
            $zodiac = $this->calculateZodiac($month, $day);
            if ($zodiac) {
                $this->updateUser($userId, ['zodiac' => $zodiac]);
            }
        }
    }

    /**
     * คำนวณราศีจากวันเดือนเกิด
     */
    private function calculateZodiac($month, $day) {
        $zodiacDates = [
            ['name' => 'ราศีมังกร', 'start' => ['month' => 12, 'day' => 22], 'end' => ['month' => 1, 'day' => 19]],
            ['name' => 'ราศีกุมภ์', 'start' => ['month' => 1, 'day' => 20], 'end' => ['month' => 2, 'day' => 18]],
            ['name' => 'ราศีมีน', 'start' => ['month' => 2, 'day' => 19], 'end' => ['month' => 3, 'day' => 20]],
            ['name' => 'ราศีเมษ', 'start' => ['month' => 3, 'day' => 21], 'end' => ['month' => 4, 'day' => 19]],
            ['name' => 'ราศีพฤษภ', 'start' => ['month' => 4, 'day' => 20], 'end' => ['month' => 5, 'day' => 20]],
            ['name' => 'ราศีเมถุน', 'start' => ['month' => 5, 'day' => 21], 'end' => ['month' => 6, 'day' => 20]],
            ['name' => 'ราศีกรกฎ', 'start' => ['month' => 6, 'day' => 21], 'end' => ['month' => 7, 'day' => 22]],
            ['name' => 'ราศีสิงห์', 'start' => ['month' => 7, 'day' => 23], 'end' => ['month' => 8, 'day' => 22]],
            ['name' => 'ราศีกันย์', 'start' => ['month' => 8, 'day' => 23], 'end' => ['month' => 9, 'day' => 22]],
            ['name' => 'ราศีตุลย์', 'start' => ['month' => 9, 'day' => 23], 'end' => ['month' => 10, 'day' => 22]],
            ['name' => 'ราศีพิจิก', 'start' => ['month' => 10, 'day' => 23], 'end' => ['month' => 11, 'day' => 21]],
            ['name' => 'ราศีธนู', 'start' => ['month' => 11, 'day' => 22], 'end' => ['month' => 12, 'day' => 21]]
        ];

        foreach ($zodiacDates as $zodiac) {
            if ($this->isDateInRange($month, $day, $zodiac['start'], $zodiac['end'])) {
                return $zodiac['name'];
            }
        }

        return null;
    }

    /**
     * เช็คว่าวันที่อยู่ในช่วงที่กำหนดหรือไม่
     */
    private function isDateInRange($month, $day, $start, $end) {
        $date = $month * 100 + $day;
        $startDate = $start['month'] * 100 + $start['day'];
        $endDate = $end['month'] * 100 + $end['day'];

        if ($startDate > $endDate) { // กรณีข้ามปี (เช่น ราศีมังกร)
            return $date >= $startDate || $date <= $endDate;
        }

        return $date >= $startDate && $date <= $endDate;
    }

    /**
     * ดึงข้อมูล User จาก ID
     */
    private function getUserById($userId) {
        try {
            return $this->db->query(
                "SELECT * FROM users WHERE id = ?",
                [$userId]
            )->fetch();
        } catch (Exception $e) {
            error_log("Error in getUserById: " . $e->getMessage());
            return null;
        }
    }
}