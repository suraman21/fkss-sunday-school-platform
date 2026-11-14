<?php
/** Safe, closed registry for Telegram registration questions. */
namespace App\Services;

use InvalidArgumentException;

final class TelegramFormRegistry
{
    /** @var array<string,array<string,mixed>> */
    private const FIELDS = [
        'full_name_am' => ['max'=>150, 'skip'=>false, 'label'=>'ሙሉ ስም', 'script'=>'ethiopic'],
        'baptismal_name' => ['max'=>150, 'skip'=>true, 'label'=>'የክርስትና ስም', 'script'=>'ethiopic'],
        'gender' => ['max'=>10, 'skip'=>false, 'label'=>'Gender / ጾታ', 'input'=>'choice', 'choices'=>['male'=>'ወንድ / Male','female'=>'ሴት / Female']],
        'dob_ec' => ['max'=>10, 'skip'=>true, 'label'=>'Ethiopian date of birth / የትውልድ ቀን'],
        'phone_number' => ['max'=>30, 'skip'=>true, 'label'=>'Phone / ስልክ'],
        'guardian_name' => ['max'=>150, 'skip'=>true, 'label'=>'የአሳዳጊ ስም', 'script'=>'ethiopic'],
        'guardian_phone1' => ['max'=>30, 'skip'=>true, 'label'=>'የአሳዳጊ ስልክ'],
        'guardian_phone2' => ['max'=>30, 'skip'=>true, 'label'=>'ተጨማሪ የአሳዳጊ ስልክ'],
        'alt_phone_number' => ['max'=>30, 'skip'=>true, 'label'=>'ተጨማሪ ስልክ'],
        'city' => ['max'=>60, 'skip'=>true, 'label'=>'City / ከተማ'],
        'sub_city' => ['max'=>60, 'skip'=>true, 'label'=>'Sub-city / ክፍለ ከተማ'],
        'woreda' => ['max'=>10, 'skip'=>true, 'label'=>'Woreda / ወረዳ'],
        'mender' => ['max'=>10, 'skip'=>true, 'label'=>'Mender / መንደር'],
        'block_number' => ['max'=>20, 'skip'=>true, 'label'=>'ብሎክ ቁጥር'],
        'house_number' => ['max'=>20, 'skip'=>true, 'label'=>'የቤት ቁጥር'],
        'work_profession' => ['max'=>100, 'skip'=>true, 'label'=>'ሙያ'],
        'guardian_city' => ['max'=>60, 'skip'=>true, 'label'=>'የአሳዳጊ ከተማ'],
        'guardian_sub_city' => ['max'=>60, 'skip'=>true, 'label'=>'የአሳዳጊ ክፍለ ከተማ'],
        'guardian_woreda' => ['max'=>10, 'skip'=>true, 'label'=>'የአሳዳጊ ወረዳ'],
        'guardian_mender' => ['max'=>10, 'skip'=>true, 'label'=>'የአሳዳጊ መንደር'],
        'guardian_block_number' => ['max'=>20, 'skip'=>true, 'label'=>'የአሳዳጊ ብሎክ'],
        'guardian_house' => ['max'=>20, 'skip'=>true, 'label'=>'የአሳዳጊ ቤት ቁጥር'],
        'education_level' => ['max'=>60, 'skip'=>true, 'label'=>'Education level / የትምህርት ደረጃ', 'input'=>'choice', 'choices'=>['kg'=>'KG','elementary'=>'Elementary (1–8)','high'=>'High School (9–12)','tvet_level1'=>'TVET Level I','tvet_level2'=>'TVET Level II','tvet_level3'=>'TVET Level III','diploma'=>'Diploma','advanced_diploma'=>'Advanced Diploma','degree'=>'Degree','masters'=>'Masters','phd'=>'PhD','other'=>'ሌላ / Other']],
        'spiritual_education' => ['max'=>100, 'skip'=>true, 'label'=>'Spiritual education / መንፈሳዊ ትምህርት', 'input'=>'choice', 'choices'=>['grade_1'=>'1ኛ ክፍል / Grade 1','grade_2'=>'2ኛ ክፍል / Grade 2','grade_3'=>'3ኛ ክፍል / Grade 3','grade_4'=>'4ኛ ክፍል / Grade 4','grade_5'=>'5ኛ ክፍል / Grade 5','grade_6'=>'6ኛ ክፍል / Grade 6','grade_7'=>'7ኛ ክፍል / Grade 7','grade_8'=>'8ኛ ክፍል / Grade 8','grade_9'=>'9ኛ ክፍል / Grade 9','grade_10'=>'10ኛ ክፍል / Grade 10','grade_11'=>'11ኛ ክፍል / Grade 11','grade_12'=>'12ኛ ክፍል / Grade 12','diploma'=>'ዲፕሎማ / Diploma','degree'=>'ዲግሪ / Degree']],
        'current_section' => ['max'=>100, 'skip'=>true, 'label'=>'Current section / ክፍል', 'input'=>'dynamic_choice'],
    ];

    /** @return list<string> */
    public static function keys(): array { return array_keys(self::FIELDS); }
    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array { return self::FIELDS; }
    public static function exists(string $key): bool { return isset(self::FIELDS[$key]); }
    public static function label(string $key): string { return (string)(self::FIELDS[$key]['label'] ?? $key); }
    /** @return array<string,string> */ public static function choices(string $key): array { $choices=self::FIELDS[$key]['choices']??[];return is_array($choices)?$choices:[]; }

    /** @return array{display:string,normalized:string} */
    public static function validate(string $key, $raw, bool $required = false): array
    {
        if (!isset(self::FIELDS[$key]) || !is_scalar($raw)) {
            throw new InvalidArgumentException('Unsupported registration field.');
        }
        $value = trim((string)$raw);
        $skip = in_array(strtolower($value), ['/skip','skip','ዝለል'], true);
        if ($skip) {
            if ($required || !self::FIELDS[$key]['skip']) {
                throw new InvalidArgumentException('This answer is required.');
            }
            return ['display'=>'', 'normalized'=>''];
        }
        if ($value === '') {
            if ($required) throw new InvalidArgumentException('This answer is required.');
            return ['display'=>'', 'normalized'=>''];
        }
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Please enter valid text.');
        }
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > (int)self::FIELDS[$key]['max']) {
            throw new InvalidArgumentException('The answer is too long.');
        }
        if((self::FIELDS[$key]['script']??'')==='ethiopic'&&!preg_match('/^[\\x{1200}-\\x{137F}\\s፡።፣፤፥፦፧፨\'’-]+$/u',$value)){
            throw new InvalidArgumentException('ይህ መረጃ በአማርኛ ፊደል ብቻ መጻፍ አለበት።');
        }

        if ($key === 'full_name_am') {
            $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) throw new InvalidArgumentException('Enter at least first and father name.');
            return ['display'=>$value, 'normalized'=>AmharicIdentityNormalizer::normalize($value)];
        }
        $choices=self::choices($key);
        if($choices!==[]){
            $normalized=null;
            foreach($choices as $choiceValue=>$label)if($value===(string)$choiceValue||$value===(string)$label){$normalized=(string)$choiceValue;break;}
            if($key==='gender'&&$normalized===null){$legacy=['ወንድ'=>'male','ሴት'=>'female'];$normalized=$legacy[$value]??null;}
            if($normalized===null)throw new InvalidArgumentException('Choose one of the available options.');
            return ['display'=>(string)$choices[$normalized], 'normalized'=>$normalized];
        }
        if ($key === 'dob_ec') {
            if (!preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
                throw new InvalidArgumentException('Use Ethiopian day/month/year, for example 12/04/2002.');
            }
            $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
            $maxDay = $month === 13 ? (($year % 4 === 3) ? 6 : 5) : 30;
            if ($month < 1 || $month > 13 || $day < 1 || $day > $maxDay || $year < 1900 || $year > 2200) {
                throw new InvalidArgumentException('That Ethiopian date is not valid.');
            }
            $canonical = sprintf('%02d/%02d/%04d', $day, $month, $year);
            return ['display'=>$canonical, 'normalized'=>$canonical];
        }
        if (in_array($key, ['phone_number','alt_phone_number','guardian_phone1','guardian_phone2'], true)) {
            if (!preg_match('/^[0-9+()\- .]+$/', $value)) throw new InvalidArgumentException('Enter a valid phone number.');
            $suffix = AmharicIdentityNormalizer::phoneSuffix($value);
            if ($suffix === '') throw new InvalidArgumentException('Enter at least 9 phone digits.');
            return ['display'=>$value, 'normalized'=>$suffix];
        }
        $nameLike = in_array($key, ['baptismal_name','guardian_name'], true);
        return ['display'=>$value, 'normalized'=>$nameLike ? AmharicIdentityNormalizer::normalize($value) : $value];
    }
}
