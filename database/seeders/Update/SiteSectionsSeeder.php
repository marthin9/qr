<?php

namespace Database\Seeders\Update;

use App\Constants\SiteSectionConst;
use App\Models\Admin\SiteSections;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SiteSectionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //site cookies data
        $data = [
            'status'    => true,
            'link'      => 'page/privacy-policy',
            'desc'      => 'We may use cookies or any other tracking technologies when you visit our website, including any other media form, mobile website, or mobile application related or connected to help customize the Site and improve your experience.',
        ];
        $cookie = SiteSections::siteCookie();
        $cookie->value = $data;
        $cookie->status = true;
        $cookie->save();

        //agent section data
        $agent_app_slug = Str::slug(SiteSectionConst::AGENT_APP_SECTION);
        if(!SiteSections::getData($agent_app_slug)->exists()){
            $site_sections = file_get_contents(base_path("database/seeders/Update/site-section.json"));
            SiteSections::insert(json_decode($site_sections,true));
        }

    }
}
