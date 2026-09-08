<?php

namespace App\Http\Controllers;

use App\Support\Guide;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guide: everything the shop can do, arranged by what somebody is trying
 * to do rather than by which menu it lives under.
 *
 * No permission, on either screen. See App\Support\Guide — the reader most
 * likely to open this is the newest assistant, holding the fewest permissions
 * in the shop, and a manual that refuses to open is not a manual. Nothing here
 * reads the shop's data; it is text, and the same text for everybody.
 */
class GuideController extends Controller
{
    public function index(): View
    {
        return view('guide.index', [
            'groups' => Guide::groups(),
            'topics' => Guide::topics(),
            'whatsNew' => Guide::whatsNew(),
        ]);
    }

    public function show(string $topic): View
    {
        $found = Guide::topic($topic);

        if ($found === null) {
            // A guide URL somebody kept from an older version, or a typo. The
            // 404 is honest; the index is one click away in the shell.
            throw new NotFoundHttpException();
        }

        return view('guide.show', [
            'slug' => $topic,
            'topic' => $found,
            'group' => Guide::groups()[$found['group']],
            // The rest of this group, so a reader who came for one thing sees
            // the neighbouring answers rather than a dead end.
            'siblings' => array_diff_key(Guide::inGroup($found['group']), [$topic => true]),
        ]);
    }
}
