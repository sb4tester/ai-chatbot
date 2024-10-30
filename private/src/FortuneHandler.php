<?php
// /home/bot.dailymu.com/private/src/FortuneHandler.php
require_once __DIR__ . '/DatabaseHandler.php';
require_once __DIR__ . '/UserHandler.php';
require_once __DIR__ . '/TagHandler.php';
require_once __DIR__ . '/CacheHandler.php';

class FortuneHandler {
    private $db;
    private $cache;
    private $userHandler;
    private $tagHandler;

    // ประเภทของการดูดวง
    private const FORTUNE_TYPES = [
        'daily' => 'ดวงประจำวัน',
        'zodiac' => 'ดวงตามราศี',
        'tarot' => 'ไพ่ทาโรต์',
        'love' => 'ดวงความรัก',
        'work' => 'ดวงการงาน',
        'finance' => 'ดวงการเงิน'
    ];

    // หมวดหมู่ของดวง
    private const FORTUNE_ASPECTS = [
        'overall' => ['ดวงโดยรวม', 'สถานการณ์', 'พลังชีวิต'],
        'love' => ['ความรัก', 'คู่ครอง', 'ความสัมพันธ์'],
        'work' => ['การงาน', 'อาชีพ', 'ความก้าวหน้า'],
        'finance' => ['การเงิน', 'โชคลาภ', 'ทรัพย์สิน'],
        'health' => ['สุขภาพ', 'พลังกาย', 'พลังใจ']
    ];

    public function __construct() {
        $this->db = DatabaseHandler::getInstance();
        $this->cache = new CacheHandler();
        $this->userHandler = new UserHandler();
        $this->tagHandler = new TagHandler();
    }

// เพิ่มฟังก์ชัน getFortune
    private function getFortune($userId, $type, $date = null) {
        try {
            $sql = "SELECT * FROM fortune_history 
                    WHERE user_id = ? 
                    AND fortune_type = ?";
            $params = [$userId, $type];

            if ($date) {
                $sql .= " AND DATE(created_at) = ?";
                $params[] = $date;
            }

            $sql .= " ORDER BY created_at DESC LIMIT 1";

            return $this->db->query($sql, $params)->fetch();

        } catch (Exception $e) {
            error_log("Error in getFortune: " . $e->getMessage());
            return null;
        }
    }

    public function getDailyFortune($userId) {
        try {
            // ตรวจสอบการดูดวงวันนี้
            $today = date('Y-m-d');
            $existingFortune = $this->getFortune($userId, 'daily', $today);
            
            if ($existingFortune) {
                return json_decode($existingFortune['fortune_result'], true);
            }

            // สร้างคำทำนายใหม่
            $user = $this->userHandler->getUserProfile($userId);
            $userTags = $this->tagHandler->generateUserProfile($userId);
            
            $fortune = $this->generatePersonalizedFortune($user, $userTags);
            
            // บันทึกคำทำนาย
            return $this->saveFortune($userId, 'daily', 'ดวงวันนี้', $fortune);

        } catch (Exception $e) {
            error_log("Error in getDailyFortune: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ดึง tags ที่เกี่ยวข้องกับแต่ละด้าน
     */
    private function getRelevantTags($userTags, $aspect) {
        $relevantTags = [];
        
        // กำหนด keywords สำหรับแต่ละด้าน
        $aspectKeywords = [
            'overall' => ['personality', 'life_events', 'general'],
            'love' => ['relationship', 'love', 'partner'],
            'work' => ['work', 'career', 'education', 'skills'],
            'finance' => ['finance', 'investment', 'business'],
            'health' => ['health', 'exercise', 'diet']
        ];

        // ดึง tags ที่เกี่ยวข้อง
        if (isset($userTags[$aspect])) {
            $relevantTags = array_merge($relevantTags, $userTags[$aspect]);
        }

        // ดึง tags จาก keywords ที่เกี่ยวข้อง
        if (isset($aspectKeywords[$aspect])) {
            foreach ($aspectKeywords[$aspect] as $keyword) {
                if (isset($userTags[$keyword])) {
                    $relevantTags = array_merge($relevantTags, $userTags[$keyword]);
                }
            }
        }

        return $relevantTags;
    }

    /**
     * สร้างคำทำนายสำหรับแต่ละด้าน
     */
    private function generateAspectFortune($aspect, $relevantTags) {
        $fortune = '';
        
        switch ($aspect) {
            case 'overall':
                $fortune = $this->generateOverallFortune($relevantTags);
                break;
            case 'love':
                $fortune = $this->generateLoveFortune($relevantTags);
                break;
            case 'work':
                $fortune = $this->generateWorkFortune($relevantTags);
                break;
            case 'finance':
                $fortune = $this->generateFinanceFortune($relevantTags);
                break;
            case 'health':
                $fortune = $this->generateHealthFortune($relevantTags);
                break;
        }

        return $fortune;
    }

    /**
     * สร้างคำทำนายด้านต่างๆ
     */
    private function generateOverallFortune($tags) {
        $base = "ดวงโดยรวมวันนี้อยู่ในเกณฑ์ดี ✨ ";
        
        if (!empty($tags['personality'])) {
            $base .= "ด้วยบุคลิกที่{$tags['personality']} ทำให้คุณมีเสน่ห์และเป็นที่ชื่นชอบของผู้คนรอบข้าง ";
        }

        return $base . "ควรรักษาความมั่นใจและทำสิ่งต่างๆ ด้วยความรอบคอบ 🌟";
    }

    private function generateLoveFortune($tags) {
        $base = "ด้านความรัก ";
        
        if (isset($tags['relationship_status'])) {
            switch ($tags['relationship_status']) {
                case 'โสด':
                    $base .= "คนโสดมีเกณฑ์จะได้พบเจอคนที่ถูกใจ อาจเป็นคนที่อยู่ในที่ทำงานหรือสังคมใกล้ตัว 💕 ";
                    break;
                case 'มีแฟน':
                    $base .= "คู่รักจะมีความเข้าใจกันมากขึ้น เป็นช่วงเวลาที่ดีในการวางแผนอนาคตร่วมกัน 💑 ";
                    break;
                default:
                    $base .= "ความรักจะมีการเปลี่ยนแปลงในทางที่ดี มีความสุขและความเข้าใจเพิ่มขึ้น ❤️ ";
            }
        }

        return $base;
    }

    private function generateWorkFortune($tags) {
        return "การงานมีความก้าวหน้า อาจได้รับโอกาสหรือโปรเจกต์ใหม่ๆ ที่ท้าทาย 💪 ควรตั้งใจทำงานและแสดงศักยภาพให้เต็มที่ 📈";
    }

    private function generateFinanceFortune($tags) {
        return "ด้านการเงิน มีเกณฑ์ได้รับโชคลาภ อาจมีรายได้พิเศษเข้ามา 💰 แต่ควรระวังเรื่องการใช้จ่ายและวางแผนการเงินให้รอบคอบ 📊";
    }

    private function generateHealthFortune($tags) {
        return "สุขภาพโดยรวมแข็งแรงดี 💪 แต่ควรระวังเรื่องการพักผ่อน พยายามนอนให้เพียงพอและออกกำลังกายสม่ำเสมอ 🧘‍♀️";
    }

    /**
     * สร้างคำแนะนำ
     */
    private function generateAdvice($fortune) {
        $advice = [
            "พยายามมองโลกในแง่ดีและรักษาความมั่นใจไว้ 🌟",
            "หมั่นทำบุญและสวดมนต์เพื่อเสริมดวงชะตา 🙏",
            "ใส่ใจเรื่องสุขภาพและการพักผ่อนให้เพียงพอ 💪",
            "ระมัดระวังการใช้จ่ายและวางแผนการเงินให้รอบคอบ 💰",
            "รักษาความสัมพันธ์ที่ดีกับคนรอบข้าง ❤️"
        ];

        return $advice[array_rand($advice)];
    }

    /**
     * สร้างเลขนำโชค
     */
    private function generateLuckyNumbers() {
        $numbers = range(0, 9);
        shuffle($numbers);
        return array_slice($numbers, 0, 3);  // เลือก 3 ตัวเลข
    }

    /**
     * สร้างสีมงคล
     */
    private function generateLuckyColors() {
        $colors = [
            'แดง' => '❤️',
            'ชมพู' => '💗',
            'ส้ม' => '🧡',
            'เหลือง' => '💛',
            'เขียว' => '💚',
            'ฟ้า' => '💙',
            'น้ำเงิน' => '🌊',
            'ม่วง' => '💜',
            'ขาว' => '⚪',
            'ดำ' => '⚫'
        ];
        
        $colorKeys = array_keys($colors);
        shuffle($colorKeys);
        $selectedColors = array_slice($colorKeys, 0, 2);  // เลือก 2 สี
        
        $result = [];
        foreach ($selectedColors as $color) {
            $result[$color] = $colors[$color];
        }
        
        return $result;
    }

    /**
     * สร้างคำทำนายส่วนบุคคล
     */
    private function generatePersonalizedFortune($user, $userTags) {
        $fortune = [];

        // ดูดวงแต่ละด้าน
        foreach (self::FORTUNE_ASPECTS as $aspect => $keywords) {
            $relevantTags = $this->getRelevantTags($userTags, $aspect);
            $fortune[$aspect] = $this->generateAspectFortune($aspect, $relevantTags);
        }

        // เพิ่มเลขและสีมงคล
        $fortune['lucky_numbers'] = $this->generateLuckyNumbers();
        $fortune['lucky_colors'] = $this->generateLuckyColors();

        // เพิ่มคำแนะนำ
        $fortune['advice'] = $this->generateAdvice($fortune);

        // จัดรูปแบบผลลัพธ์
        return [
            'overall' => $fortune['overall'],
            'aspects' => [
                'love' => $fortune['love'],
                'work' => $fortune['work'],
                'finance' => $fortune['finance'],
                'health' => $fortune['health']
            ],
            'lucky' => [
                'numbers' => implode(", ", $fortune['lucky_numbers']),
                'colors' => $fortune['lucky_colors']
            ],
            'advice' => $fortune['advice'],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }



    /**
     * ดูดวงด้วยไพ่ทาโรต์
     */
    public function getTarotFortune($userId, $question = null) {
        try {
            // สุ่มไพ่
            $cards = $this->drawTarotCards();
            
            // ตีความไพ่
            $interpretation = $this->interpretTarotCards($cards, $question);

            // บันทึกคำทำนาย
            $fortune = [
                'cards' => $cards,
                'interpretation' => $interpretation,
                'question' => $question
            ];

            return $this->saveFortune($userId, 'tarot', $question, $fortune);

        } catch (Exception $e) {
            error_log("Error in getTarotFortune: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * สร้างคำทำนายตามราศี
     */
    private function generateZodiacFortune($zodiacData) {
        $fortune = [];

        // คำนวณดวงดาวประจำวัน
        $fortune['planetary'] = $this->calculatePlanetaryInfluence($zodiacData);

        // สร้างคำทำนายแต่ละด้าน
        foreach (self::FORTUNE_ASPECTS as $aspect => $keywords) {
            $fortune[$aspect] = $this->generateZodiacAspect($zodiacData, $aspect);
        }

        // เพิ่มคำแนะนำ
        $fortune['advice'] = $this->generateZodiacAdvice($zodiacData);

        return $fortune;
    }


    /**
     * บันทึกคำทำนาย
     */
    private function saveFortune($userId, $type, $question, $fortune) {
        try {
            // แปลง array เป็น JSON ด้วย options พิเศษ
            $fortuneJson = json_encode($fortune, 
                JSON_UNESCAPED_UNICODE | 
                JSON_UNESCAPED_SLASHES | 
                JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON encode error: " . json_last_error_msg());
            }

            $this->db->query(
                "INSERT INTO fortune_history 
                 (user_id, fortune_type, question, fortune_result, additional_info)
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $userId,
                    $type,
                    $question,
                    $fortuneJson,
                    json_encode(['timestamp' => time()], JSON_UNESCAPED_UNICODE)
                ]
            );

            return $fortune;

        } catch (Exception $e) {
            error_log("Error in saveFortune: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ดึงประวัติการดูดวง
     */
    public function getFortuneHistory($userId, $limit = 5) {
        try {
            return $this->db->query(
                "SELECT * FROM fortune_history 
                 WHERE user_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$userId, $limit]
            )->fetchAll();

        } catch (Exception $e) {
            error_log("Error in getFortuneHistory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Utility functions
     */
    /**
     * ดึงข้อมูลราศี
     */
    private function getZodiacData($zodiacName) {
        try {
            // ตรวจสอบข้อมูลในฐานข้อมูล
            $zodiacData = $this->db->query(
                "SELECT * FROM zodiac_data WHERE zodiac_name = ?",
                [$zodiacName]
            )->fetch();

            // ถ้าไม่มีข้อมูลใน DB ใช้ข้อมูลพื้นฐาน
            if (!$zodiacData) {
                $zodiacData = $this->getDefaultZodiacData($zodiacName);
            }

            return $zodiacData;
        } catch (Exception $e) {
            error_log("Error in getZodiacData: " . $e->getMessage());
            return $this->getDefaultZodiacData($zodiacName);
        }
    }

    /**
     * ข้อมูลราศีพื้นฐาน
     */
    private function getDefaultZodiacData($zodiacName) {
        $zodiacInfo = [
            'ราศีเมษ' => [
                'element' => 'ไฟ',
                'lucky_colors' => ['แดง', 'ส้ม'],
                'lucky_numbers' => '1,9',
                'description' => 'ราศีเมษเป็นราศีแห่งความกล้าหาญ มีความเป็นผู้นำสูง'
            ],
            'ราศีพฤษภ' => [
                'element' => 'ดิน',
                'lucky_colors' => ['เขียว', 'ชมพู'],
                'lucky_numbers' => '2,6',
                'description' => 'ราศีพฤษภเป็นราศีที่มีความอดทน รักความมั่นคง'
            ],
            'ราศีเมถุน' => [
                'element' => 'ลม',
                'lucky_colors' => ['เหลือง', 'ฟ้า'],
                'lucky_numbers' => '3,7',
                'description' => 'ราศีเมถุนเป็นราศีที่ฉลาด มีไหวพริบดี'
            ],
            'ราศีกรกฎ' => [
                'element' => 'น้ำ',
                'lucky_colors' => ['เงิน', 'ขาว'],
                'lucky_numbers' => '2,7',
                'description' => 'ราศีกรกฎเป็นราศีที่อ่อนโยน มีความเห็นอกเห็นใจผู้อื่น'
            ],
            'ราศีสิงห์' => [
                'element' => 'ไฟ',
                'lucky_colors' => ['ทอง', 'แดง'],
                'lucky_numbers' => '1,4',
                'description' => 'ราศีสิงห์เป็นราศีที่มีความเป็นผู้นำ มีเสน่ห์'
            ],
            'ราศีกันย์' => [
                'element' => 'ดิน',
                'lucky_colors' => ['น้ำตาล', 'เขียว'],
                'lucky_numbers' => '5,8',
                'description' => 'ราศีกันย์เป็นราศีที่มีความละเอียดรอบคอบ'
            ],
            'ราศีตุลย์' => [
                'element' => 'ลม',
                'lucky_colors' => ['ฟ้า', 'ชมพู'],
                'lucky_numbers' => '6,9',
                'description' => 'ราศีตุลย์เป็นราศีที่รักความยุติธรรม มีเสน่ห์'
            ],
            'ราศีพิจิก' => [
                'element' => 'น้ำ',
                'lucky_colors' => ['แดงเข้ม', 'ม่วง'],
                'lucky_numbers' => '2,4',
                'description' => 'ราศีพิจิกเป็นราศีที่มีพลังและความมุ่งมั่นสูง'
            ],
            'ราศีธนู' => [
                'element' => 'ไฟ',
                'lucky_colors' => ['น้ำเงิน', 'ม่วง'],
                'lucky_numbers' => '3,9',
                'description' => 'ราศีธนูเป็นราศีที่มองโลกในแง่ดี ชอบผจญภัย'
            ],
            'ราศีมังกร' => [
                'element' => 'ดิน',
                'lucky_colors' => ['ดำ', 'น้ำตาล'],
                'lucky_numbers' => '4,8',
                'description' => 'ราศีมังกรเป็นราศีที่มีความทะเยอทะยาน มุ่งมั่น'
            ],
            'ราศีกุมภ์' => [
                'element' => 'ลม',
                'lucky_colors' => ['ฟ้า', 'เทา'],
                'lucky_numbers' => '4,7',
                'description' => 'ราศีกุมภ์เป็นราศีที่มีความคิดสร้างสรรค์ มีความเป็นตัวของตัวเอง'
            ],
            'ราศีมีน' => [
                'element' => 'น้ำ',
                'lucky_colors' => ['เขียวน้ำทะเล', 'ม่วง'],
                'lucky_numbers' => '3,9',
                'description' => 'ราศีมีนเป็นราศีที่มีความอ่อนโยน มีจินตนาการสูง'
            ]
        ];

        return [
            'zodiac_name' => $zodiacName,
            'element' => $zodiacInfo[$zodiacName]['element'] ?? 'ไม่ระบุ',
            'lucky_color' => implode(', ', $zodiacInfo[$zodiacName]['lucky_colors'] ?? ['ไม่ระบุ']),
            'lucky_number' => $zodiacInfo[$zodiacName]['lucky_numbers'] ?? 'ไม่ระบุ',
            'description' => $zodiacInfo[$zodiacName]['description'] ?? 'ไม่มีข้อมูล'
        ];
    }

    /**
     * คำนวณอิทธิพลดวงดาว
     */
    private function calculatePlanetaryInfluence($zodiacData) {
        $elements = [
            'ไฟ' => ['ดาวอังคาร', 'ดวงอาทิตย์'],
            'ดิน' => ['ดาวเสาร์', 'ดาวพฤหัส'],
            'ลม' => ['ดาวพุธ', 'ดาวอังคาร'],
            'น้ำ' => ['ดวงจันทร์', 'ดาวศุกร์']
        ];

        $element = $zodiacData['element'];
        $planets = $elements[$element] ?? ['ดวงอาทิตย์'];

        return "ได้รับอิทธิพลจาก" . implode('และ', $planets) . 
               " ส่งผลให้" . $this->getPlanetaryEffect($planets[0]);
    }

    /**
     * ผลกระทบจากดวงดาว
     */
    private function getPlanetaryEffect($planet) {
        $effects = [
            'ดาวอังคาร' => 'มีพลังและความกระตือรือร้นสูง',
            'ดวงอาทิตย์' => 'มีความมั่นใจและความเป็นผู้นำ',
            'ดาวเสาร์' => 'มีความรอบคอบและความอดทน',
            'ดาวพฤหัส' => 'มีโชคและการขยายตัวที่ดี',
            'ดาวพุธ' => 'มีปัญญาและการสื่อสารที่ดี',
            'ดวงจันทร์' => 'มีความอ่อนโยนและความเข้าใจ',
            'ดาวศุกร์' => 'มีเสน่ห์และความสัมพันธ์ที่ดี'
        ];

        return $effects[$planet] ?? 'มีพลังงานที่ดี';
    }

    /**
     * สร้างคำทำนายตามราศี
     */
    private function generateZodiacAspect($zodiacData, $aspect) {
        $base = match($aspect) {
            'overall' => "ด้วยอิทธิพลของ{$zodiacData['element']}ธาตุ " . 
                        $this->calculatePlanetaryInfluence($zodiacData),
            'love' => "ด้านความรัก คนราศี{$zodiacData['zodiac_name']}ช่วงนี้ " .
                     $this->getZodiacLoveFortune($zodiacData['element']),
            'work' => "เรื่องการงาน " . $this->getZodiacWorkFortune($zodiacData['element']),
            'finance' => "ด้านการเงิน " . $this->getZodiacFinanceFortune($zodiacData['element']),
            'health' => "สุขภาพ " . $this->getZodiacHealthFortune($zodiacData['element']),
            default => "ดวงดาวส่งผลดีต่อคุณ"
        };

        return $base . " " . $this->getZodiacAdvice($zodiacData['element']);
    }

    private function getZodiacLoveFortune($element) {
        $fortunes = [
            'ไฟ' => "มีเสน่ห์และความมั่นใจสูง มีโอกาสพบคนถูกใจ ❤️",
            'ดิน' => "ความรักมั่นคง มีความสุขกับคนรอบข้าง 💑",
            'ลม' => "มีโอกาสใหม่ๆ ในความรัก เป็นช่วงที่ดีสำหรับการเริ่มต้น 💕",
            'น้ำ' => "ความรักลึกซึ้ง เข้าใจกันมากขึ้น มีความสุขทางใจ 💖"
        ];

        return $fortunes[$element] ?? "ความรักมีแนวโน้มที่ดี 💝";
    }

    private function getZodiacWorkFortune($element) {
        $fortunes = [
            'ไฟ' => "มีพลังในการทำงาน ได้รับการยอมรับ 💪",
            'ดิน' => "งานมั่นคง มีโอกาสก้าวหน้า 📈",
            'ลม' => "มีไอเดียใหม่ๆ ในการทำงาน เหมาะกับงานสร้างสรรค์ 🎨",
            'น้ำ' => "งานราบรื่น ใช้ความรู้สึกนำทาง ประสบความสำเร็จ 🌊"
        ];

        return $fortunes[$element] ?? "การงานมีความก้าวหน้า 📊";
    }

    private function getZodiacFinanceFortune($element) {
        $fortunes = [
            'ไฟ' => "มีโอกาสทางการเงินดี รายได้เพิ่มขึ้น 💰",
            'ดิน' => "การเงินมั่นคง มีโอกาสลงทุน 💎",
            'ลม' => "มีช่องทางรายได้ใหม่ๆ โชคลาภเข้ามา 🍀",
            'น้ำ' => "การเงินไหลลื่น มีเงินสำรองเพียงพอ 💫"
        ];

        return $fortunes[$element] ?? "การเงินมีแนวโน้มที่ดี 💵";
    }

    private function getZodiacHealthFortune($element) {
        $fortunes = [
            'ไฟ' => "พลังงานสูง ควรออกกำลังกายสม่ำเสมอ 🏃",
            'ดิน' => "สุขภาพแข็งแรง ทานอาหารที่มีประโยชน์ 🥗",
            'ลม' => "ร่างกายยืดหยุ่นดี เหมาะกับการฝึกโยคะ 🧘",
            'น้ำ' => "สุขภาพจิตดี ควรพักผ่อนให้เพียงพอ 😴"
        ];

        return $fortunes[$element] ?? "สุขภาพโดยรวมดี 💪";
    }
    private function getZodiacAdvice($element) {
        $advice = [
            'ไฟ' => "ควรระวังความใจร้อน ใช้พลังงานอย่างสร้างสรรค์ 🔥",
            'ดิน' => "รักษาความมั่นคง วางแผนอนาคตให้ดี 🌱",
            'ลม' => "เปิดใจรับสิ่งใหม่ๆ แต่อย่าลืมรากเหง้า 🍃",
            'น้ำ' => "ใช้ความรู้สึกนำทาง แต่อย่าลืมใช้เหตุผลประกอบ 🌊"
        ];

        return $advice[$element] ?? "รักษาสมดุลในชีวิต และมองโลกในแง่ดี ✨";
    }

    /**
     * ดูดวงตามราศี
     */
    public function getZodiacFortune($userId, $zodiacName = null) {
        try {
            $user = $this->userHandler->getUserProfile($userId);
            
            // ถ้าไม่ระบุราศี ใช้ราศีของ user
            $zodiac = $zodiacName ?? $user['zodiac'];
            if (!$zodiac) {
                throw new Exception("ไม่พบข้อมูลราศี กรุณาระบุราศีของคุณก่อนค่ะ");
            }

            // ดึงข้อมูลราศี
            $zodiacData = $this->getZodiacData($zodiac);
            
            // สร้างคำทำนาย
            $fortune = [
                'zodiac' => $zodiacData['zodiac_name'],
                'element' => $zodiacData['element'],
                'description' => $zodiacData['description'],
                'readings' => [
                    'overall' => $this->generateZodiacAspect($zodiacData, 'overall'),
                    'love' => $this->generateZodiacAspect($zodiacData, 'love'),
                    'work' => $this->generateZodiacAspect($zodiacData, 'work'),
                    'finance' => $this->generateZodiacAspect($zodiacData, 'finance'),
                    'health' => $this->generateZodiacAspect($zodiacData, 'health')
                ],
                'lucky' => [
                    'colors' => explode(', ', $zodiacData['lucky_color']),
                    'numbers' => explode(',', $zodiacData['lucky_number'])
                ],
                'planetary' => $this->calculatePlanetaryInfluence($zodiacData)
            ];

            // บันทึกคำทำนาย
            return $this->saveFortune($userId, 'zodiac', $zodiac, $fortune);

        } catch (Exception $e) {
            error_log("Error in getZodiacFortune: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * สุ่มไพ่ทาโรต์
     */
    private function drawTarotCards($count = 3) {
        try {
            // เพิ่มข้อมูลไพ่ทาโรต์ถ้ายังไม่มี
            $this->initializeTarotCards();

            // ใช้ LIMIT โดยตรงแทนการใช้ parameter
            $sql = "SELECT * FROM tarot_cards ORDER BY RAND() LIMIT " . (int)$count;
            $cards = $this->db->query($sql)->fetchAll();

            // สุ่มว่าไพ่หงายหรือคว่ำ
            foreach ($cards as &$card) {
                $card['is_reversed'] = (rand(0, 1) == 1);
            }

            return $cards;

        } catch (Exception $e) {
            error_log("Error in drawTarotCards: " . $e->getMessage());
            // ถ้าไม่สามารถดึงไพ่ได้ ใช้ไพ่ default
            return $this->getDefaultTarotCards($count);
        }
    }

    /**
     * ไพ่ default กรณีมีปัญหากับฐานข้อมูล
     */
    private function getDefaultTarotCards($count) {
        $defaultCards = [
            [
                'name' => 'The Fool',
                'type' => 'Major Arcana',
                'meaning_upright' => 'การเริ่มต้นใหม่ โอกาสใหม่ การผจญภัย',
                'meaning_reversed' => 'ความประมาท การตัดสินใจผิดพลาด',
                'is_reversed' => (rand(0, 1) == 1)
            ],
            [
                'name' => 'The Magician',
                'type' => 'Major Arcana',
                'meaning_upright' => 'ความสามารถ พลังสร้างสรรค์ ความสำเร็จ',
                'meaning_reversed' => 'การใช้พลังในทางที่ผิด การหลอกลวง',
                'is_reversed' => (rand(0, 1) == 1)
            ],
            [
                'name' => 'The High Priestess',
                'type' => 'Major Arcana',
                'meaning_upright' => 'ญาณหยั่งรู้ ความลึกลับ สัญชาตญาณ',
                'meaning_reversed' => 'ความลังเล การปิดกั้นสัญชาตญาณ',
                'is_reversed' => (rand(0, 1) == 1)
            ]
        ];

        return array_slice($defaultCards, 0, $count);
    }

    /**
     * เพิ่มข้อมูลไพ่ทาโรต์พื้นฐาน
     */
    private function initializeTarotCards() {
        try {
            // เช็คว่ามีข้อมูลไพ่หรือยัง
            $count = $this->db->query("SELECT COUNT(*) as count FROM tarot_cards")->fetch();
            
            if ($count['count'] == 0) {
                // เพิ่มข้อมูลไพ่พื้นฐาน
                $basicCards = [
                    [
                        'name' => 'The Fool',
                        'type' => 'Major Arcana',
                        'meaning_upright' => 'การเริ่มต้นใหม่ โอกาสใหม่ การผจญภัย ความไร้เดียงสา',
                        'meaning_reversed' => 'ความประมาท การตัดสินใจผิดพลาด ความไม่รอบคอบ',
                        'keywords' => 'adventure,beginning,innocence'
                    ],
                    [
                        'name' => 'The Magician',
                        'type' => 'Major Arcana',
                        'meaning_upright' => 'ความสามารถ พลังสร้างสรรค์ ความสำเร็จ การได้ใช้ศักยภาพ',
                        'meaning_reversed' => 'การใช้พลังในทางที่ผิด การหลอกลวง ความไม่มั่นใจ',
                        'keywords' => 'power,skill,creativity'
                    ],
                    [
                        'name' => 'The High Priestess',
                        'type' => 'Major Arcana',
                        'meaning_upright' => 'ญาณหยั่งรู้ ความลึกลับ สัญชาตญาณ การเรียนรู้',
                        'meaning_reversed' => 'ความลังเล การปิดกั้นสัญชาตญาณ ความไม่แน่ใจ',
                        'keywords' => 'intuition,mystery,spirituality'
                    ]
                ];

                foreach ($basicCards as $card) {
                    $this->db->query(
                        "INSERT INTO tarot_cards 
                         (name, type, meaning_upright, meaning_reversed, keywords)
                         VALUES (?, ?, ?, ?, ?)",
                        [
                            $card['name'],
                            $card['type'],
                            $card['meaning_upright'],
                            $card['meaning_reversed'],
                            $card['keywords']
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            error_log("Error initializing tarot cards: " . $e->getMessage());
            // ไม่ต้อง throw exception เพราะมี fallback แล้ว
        }
    }

    /**
     * ตีความไพ่ทาโรต์
     */
    private function interpretTarotCards($cards, $question = null) {
        $positions = [
            0 => ['name' => 'อดีต/สาเหตุ', 'desc' => 'สิ่งที่ผ่านมาหรือสาเหตุของสถานการณ์'],
            1 => ['name' => 'ปัจจุบัน/สถานการณ์', 'desc' => 'สถานการณ์ปัจจุบัน'],
            2 => ['name' => 'อนาคต/แนวโน้ม', 'desc' => 'สิ่งที่จะเกิดขึ้นหรือแนวทางที่ควรทำ']
        ];

        $interpretation = [];

        foreach ($cards as $index => $card) {
            $position = $positions[$index] ?? ['name' => 'เพิ่มเติม', 'desc' => 'ข้อมูลเพิ่มเติม'];
            $meaning = $card['is_reversed'] ? $card['meaning_reversed'] : $card['meaning_upright'];

            $interpretation[] = [
                'position' => $position['name'],
                'description' => $position['desc'],
                'card' => [
                    'name' => $card['name'],
                    'is_reversed' => $card['is_reversed'],
                    'meaning' => $meaning
                ]
            ];
        }

        return [
            'question' => $question,
            'cards' => $interpretation,
            'overall_meaning' => $this->generateOverallTarotMeaning($interpretation)
        ];
    }

    /**
     * สร้างคำทำนายรวมจากไพ่ทั้งหมด
     */
    private function generateOverallTarotMeaning($interpretation) {
        $message = "จากการเปิดไพ่ทั้งหมด แสดงให้เห็นว่า:\n\n";

        foreach ($interpretation as $reading) {
            $cardStatus = $reading['card']['is_reversed'] ? "(คว่ำ)" : "(หงาย)";
            $message .= "🎴 {$reading['position']}: ไพ่ {$reading['card']['name']} {$cardStatus}\n";
            $message .= "   {$reading['card']['meaning']}\n\n";
        }

        return $message;
    }

}