<?php
/** Per-school configurable message strings for the Telegram bot. */
namespace App\Services;

/**
 * Message keys and their Amharic defaults. All user-facing bot copy goes through
 * this service so a deployment can override strings per school without editing
 * code. English is provided for reference but the bot is Amharic-first.
 */
final class TelegramBotMessages
{
    /** @return array<string,array{am:string,en?:string}> */
    public static function defaults(): array
    {
        return [
            'welcome' => [
                'am' => "ሰላም! እንኳን ወደ ውሉደ ብርሃን የአባላት ምዝገባ ስርዓት በደህና መጡ።\n\nእዚህ አዲስ አባል መመዝገብ፣ የቀድሞ አባል መረጃ መፈለግ እና የምዝገባ ሂደትን ማስተዳደር ይችላሉ።",
            ],
            'access_denied' => [
                'am' => "ይህ መለያ ገና አልተፈቀደም።\n\nTelegram ID: {telegram_id}\nPairing request: {pairing_id}\nAsk an administrator to link it to your active system account.",
            ],
            'cancelled' => [
                'am' => "ምዝገባው ተሰርዟል።",
            ],
            'daily_limit_reached' => [
                'am' => "የዕለቱ የምዝገባ ገደብ ደርሷል። አስተዳዳሪ ያነጋግሩ።",
            ],
            'resume_no_draft' => [
                'am' => "ያልተጠናቀቀ ምዝገባ የለም። አዲስ ለመጀመር «አዲስ አባል መመዝገብ»ን ይጫኑ።",
            ],
            'prompt_full_name' => [
                'am' => "የአባሉን ሙሉ ስም ያስገቡ።\n\nእባክዎ የመጀመሪያ ስም፣ የአባት ስም እና የአያት ስም በትክክል ይጻፉ።\n\nምሳሌ፦ ሚካኤል ገብረ ማርያም ተስፋዬ",
            ],
            'name_too_short' => [
                'am' => "እባክዎ ቢያንስ የራስ እና የአባት ስም ያስገቡ።",
            ],
            'name_script_error' => [
                'am' => "ስሙ በአማርኛ ፊደል ብቻ ይጻፉ።",
            ],
            'searching_duplicates' => [
                'am' => "🔍 በመፈለግ ላይ... ተመሳሳይ አባላትን ካሉ እያጣራሁ ነው።",
            ],
            'duplicate_header' => [
                'am' => "⚠️ ተመሳሳይ ሊሆኑ የሚችሉ አባላት ተገኝተዋል\n\nከዚህ በፊት በስርዓቱ ውስጥ የተመዘገቡ ከዚህ ስም ጋር ተመሳሳይነት ያላቸው አባላት ተገኝተዋል።\n\nእባክዎ ከሚመዘገበው ሰው ጋር በማረጋገጥ መረጃዎቹን ያወዳድሩ።",
            ],
            'no_duplicates' => [
                'am' => "✅ በአሁኑ ፍለጋ ውስጥ ተመሳሳይ አባል አልተገኘም።\n\nይህ ሰው ከዚህ በፊት አለመመዝገቡን ዋስትና አይሰጥም። ተጨማሪ መረጃ ሲገባ ተጨማሪ ምርመራ ይካሄዳል።\n\nወደ ቀሪው የምዝገባ መረጃ ይቀጥሉ።",
            ],
            'candidate_index' => [
                'am' => "እጩ {index} ከ{total}",
            ],
            'match_high' => ['am' => "ከፍተኛ ተመሳሳይነት"],
            'match_medium' => ['am' => "መካከለኛ ተመሳሳይነት"],
            'match_low' => ['am' => "ዝቅተኛ ተመሳሳይነት"],
            'verify_prompt' => [
                'am' => "የአባሉን መረጃ ከሚመዘገበው ሰው ጋር በማወዳደር ያረጋግጡ።",
            ],
            'same_person_confirmed' => [
                'am' => "✅ ተመሳሳይ አባል መሆኑ ተረጋግጧል።\n\nይህ ሰው ቀደም ሲል በስርዓቱ ውስጥ ተመዝግቧል።\nየአባል መለያ፦ {member_code}\n\nአዲስ መዝገብ አልተፈጠረም።",
            ],
            'different_person_confirmed' => [
                'am' => "✅ የተለያዩ ሰዎች መሆናቸው ተረጋግጧል።\n\nአዲሱን አባል መመዝገብ ይችላሉ።",
            ],
            'uncertain_prompt' => [
                'am' => "❓ ማረጋገጫው ገና አልተጠናቀቀም።\n\nየአባሉን ማንነት በበቂ ሁኔታ ማረጋገጥ ካልቻሉ እባክዎ ምዝገባውን አያጠናቅቁ።",
            ],
            'escalated' => [
                'am' => "📨 ምዝገባው ለኃላፊ ተልኳል።\n\nማጣቀሻ፦ {public_id}\nኃላፊው ሲያጣራ ይመለሳል።",
            ],
            'paused' => [
                'am' => "⏸️ ምዝገባው ለጊዜው ቆይቷል። /resume በመጫን ይቀጥሉ።",
            ],
            'prompt_baptismal_name' => ['am' => "የአባሉን የክርስትና ስም ያስገቡ። ከሌለ «ዝለል»ን ይጫኑ።"],
            'prompt_gender' => ['am' => "ፆታ ይምረጡ፦"],
            'prompt_dob_ec' => ['am' => "የትውልድ ቀን በኢትዮጵያ ዘመን አቆጣጠር ያስገቡ። (ቀን/ወር/ዓመት)\n\nምሳሌ፦ 12/04/2002"],
            'prompt_father_name' => ['am' => "የአባሉን የአባት ስም ያስገቡ።"],
            'prompt_mother_name' => ['am' => "የአባሉን የእናት ስም ያስገቡ። ካልታወቀ «ዝለል»ን ይጫኑ።"],
            'prompt_phone' => ['am' => "የስልክ ቁጥር ያስገቡ። (09... or +2519...) ከሌለ «ዝለል»ን ይጫኑ።"],
            'prompt_guardian_name' => ['am' => "የአሳዳጊ ስም ያስገቡ። (ለሕፃናት) ከሌለ «ዝለል»ን ይጫኑ።"],
            'prompt_guardian_phone' => ['am' => "የአሳዳጊ ስልክ ቁጥር ያስገቡ። ከሌለ «ዝለል»ን ይጫኑ።"],
            'prompt_city' => ['am' => "ከተማ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።"],
            'prompt_sub_city' => ['am' => "ክፍለ ከተማ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።"],
            'prompt_woreda' => ['am' => "ወረዳ ያስገቡ። «ዝለል»ን መጫንም ይችላሉ።"],
            'prompt_current_section' => ['am' => "ክፍል / ሴክሽን ይምረጡ፦"],
            'prompt_education_level' => ['am' => "የትምህርት ደረጃ ይምረጡ፦"],
            'prompt_spiritual_education' => ['am' => "መንፈሳዊ ትምህርት ደረጃ ይምረጡ፦"],
            'prompt_student_photo' => ['am' => "የአባሉን ፎቶ ይላኩ (ፎቶ ወይም PDF)። «ዝለል»ን መጫንም ይችላሉ።"],
            'invalid_input' => ['am' => "⚠️ {error}\n\n{prompt}"],
            'field_saved' => ['am' => "✅ {label} ተቀምጧል።"],
            'edit_menu_title' => ['am' => "የትኛውን መረጃ ማስተካከል ይፈልጋሉ?"],
            'edit_prompt' => ['am' => "አሁን ያለው {label}፦\n{current}\n\nአዲሱን ያስገቡ።"],
            'edit_done' => ['am' => "✅ የ{label} ማስተካከያው ተሳክቷል።"],
            'review_header' => ['am' => "📋 የአባል ምዝገባ ማጠቃለያ\n\nእባክዎ የተሞሉትን መረጃዎች በጥንቃቄ ይመርምሩ።"],
            'review_duplicate_warn' => ['am' => "🚨 ያልተፈታ ከፍተኛ ተመሳሳይነት ያለው እጩ አለ። ከማስረከብዎ በፊት መፍታት አለብዎት።"],
            'submit_success' => [
                'am' => "✅ የምዝገባ መረጃው በተሳካ ሁኔታ ተልኳል።\n\nየአባሉ ምዝገባ ለHR ማጽደቅ ቀርቧል።\n- የምዝገባ መለያ፦ {public_id}\n- ሁኔታ፦ በማጽደቅ ላይ\n- ቀን፦ {date}",
            ],
            'approval_notice' => [
                'am' => "✅ HR ምዝገባውን አጽድቋል።\nመዝገብ፦ {public_id}\nአባል ቁጥር፦ {member_code}\nአባሉ በተሳካ ሁኔታ ተፈጥሯል።",
            ],
            'review_decision_notice' => [
                'am' => "HR ግምገማ፦ {decision}\nመዝገብ፦ {public_id}\nማስታወሻ፦ {note}",
            ],
            'correction_request_notice' => [
                'am' => "HR ማስተካከያ ጠይቋል።\nመዝገብ፦ {public_id}\nማስተካከል ያለባቸው፦ {fields}\n\n/resume በመጫን ያስተካክሉ።",
            ],
            'search_prompt' => ['am' => "🔎 ለመፈለግ የአባሉን ስም ወይም መለያ ቁጥር ያስገቡ።"],
            'search_no_results' => ['am' => "ምንም አልተገኘም።"],
            'my_registrations_empty' => ['am' => "እስካሁን ያስመዘገቧቸው አባላት የሉም።"],
            'not_authorized_pm' => ['am' => "This bot works only in an authorized private chat."],
            'processing_retry' => ['am' => "የቀድሞው መልስ እየተሰራ ነው። ትንሽ ቆይተው እንደገና ይሞክሩ።"],
            'final_validation_blocked' => ['am' => "🚨 {blocker}\n\nከማስረከብዎ በፊት ይህንን መፍታት አለብዎት።"],
            'next_candidate' => ['am' => "ቀጣይ እጩ"],
            'prev_candidate' => ['am' => "ያለፈው እጩ"],
        ];
    }
}
