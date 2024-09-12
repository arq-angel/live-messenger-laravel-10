<?php

namespace App\Http\Controllers;

use App\Events\Message as MessageEvent;
use App\Models\Favorite;
use App\Models\Message;
use App\Models\User;
use App\Traits\FileUploadTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MessengerController extends Controller
{
    use FileUploadTrait;

    public function index()
    {
        $favoriteList = Favorite::with("user:id,name,avatar")->where("user_id", Auth::user()->id)->get();
        return view('messenger.index', compact('favoriteList'));
    }

    /** Search User Profiles */
    public function search(Request $request)
    {
        $getRecords = null;
        $query = $request['query'];
        $records = User::where('id', '!=', Auth::user()->id)
            ->where('name', 'LIKE', "%{$query}%")
            ->orWhere('user_name', 'LIKE', "%{$query}%")
            ->paginate(10);

        if ($records->total() < 1) {
            $getRecords .= "<p class='text-center'>Nothing to show!</p>";
        }

        foreach ($records as $record) {
            $getRecords .= view('messenger.components.search-item', compact('record'))->render();
        }

        return response()->json([
            'records' => $getRecords,
            'last_page' => $records->lastPage(),
        ]);
    }

    // fetch user by id
    public function fetchIdInfo(Request $request)
    {
        $fetch = User::where('id', '=', $request['id'])->first();
        $favorite = Favorite::where(["user_id" => Auth::user()->id, "favorite_id" => $fetch->id])->exists();
        $sharedPhotos = Message::where("from_id", Auth::user()->id)->where('to_id', $request->id)->whereNotNull("attachment")
            ->orWhere("from_id", $request->id)->where('to_id', AUth::user()->id)->whereNotNull("attachment")
            ->latest()->get();

        $content = "";

        foreach ($sharedPhotos as $photo) {
            $content .= view("messenger.components.gallery-item", compact('photo'))->render();
        }

        return response()->json([
            'fetch' => $fetch,
            'favorite' => $favorite,
            'shared_photos' => $content,
        ]);
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            "message" => ["nullable"],
            "id" => ["required", "integer"],
            "temporaryMsgId" => ["required"],
            "attachment" => ["nullable", "max:1024", "image"],
        ]);

        // store the message in DB
        $attachmentPath = $this->uploadFile($request, "attachment");
        $message = new Message();
        $message->from_id = Auth::user()->id;
        $message->to_id = $request->id;
        $message->body = $request->message;
        if ($attachmentPath) {
            $message->attachment = json_encode($attachmentPath);
        }
        $message->save();

        // broadcast event
        MessageEvent::dispatch($message);

        return response()->json([
            'message' => $message->attachment ? $this->messageCard($message, true) : $this->messageCard($message),
            'tempID' => $request->temporaryMsgId,
        ]);
    }

    private function messageCard($message, $attachment = false)
    {
        return view("messenger.components.message-card", compact('message', 'attachment'))->render();
    }

    // fetch messages from database
    public function fetchMessages(Request $request)
    {
        $messages = Message::where("from_id", Auth::user()->id)->where('to_id', $request->id)
            ->orWhere("from_id", $request->id)->where('to_id', AUth::user()->id)
            ->latest()->paginate(20);

        $response = [
            'last_page' => $messages->lastPage(),
            'last_message' => $messages->last(),
            'messages' => '',
        ];

        if (count($messages) < 1) {
            $response['messages'] = "<div class='d-flex justify-content-center no_messages align-items-center mx-auto h-100'><p>Say 'Hi' and start messaging.</p></div>";
            return response()->json($response);
        }

        $allMessages = '';
        foreach ($messages->reverse() as $message) {
            $allMessages .= $this->messageCard($message, $message->attachment ? true : false);
        }

        $response['messages'] = $allMessages;

        return response()->json($response);
    }

    // fetch contacts from database
    public function fetchContacts(Request $request)
    {
        $users = Message::join('users', function ($join) {
            $join->on('messages.from_id', '=', 'users.id')
                ->orOn('messages.to_id', '=', 'users.id');
        })
            ->where(function ($q) {
                $q->where("messages.from_id", Auth::user()->id)
                    ->orWhere("messages.to_id", Auth::user()->id);
            })
            ->where("users.id", "!=", Auth::user()->id)
            ->select('users.*', DB::raw('MAX(messages.created_at) max_created_at'))
            ->orderBy("max_created_at", "DESC")
            ->groupBy("users.id")
            ->paginate(10);

        if (count($users) > 0) {
            $contacts = '';
            foreach ($users as $user) {
                $contacts .= $this->getContactItem($user);
            }
        } else {
            $contacts = "<p class='text-center no_contact'>Your contact list is empty!</p>";
        }

        return response()->json([
            'contacts' => $contacts,
            'last_page' => $users->lastPage(),
        ]);
    }

    private function getContactItem($user)
    {
        $lastMessage = Message::where("from_id", Auth::user()->id)->where('to_id', $user->id)
            ->orWhere("from_id", $user->id)->where('to_id', AUth::user()->id)
            ->latest()->first();

        $unseenCounter = Message::where("from_id", $user->id)->where("to_id", Auth::user()->id)->where(
            "seen",
            0
        )->count();

        return view("messenger.components.contact-list-item", compact("lastMessage", "unseenCounter", "user"))->render(
        );
    }

    // update contact item
    public function updateContactItem(Request $request)
    {
        // get user data
        $user = User::where('id', '=', $request->user_id)->first();

        if (!$user) {
            return response()->json([
                'message' => 'user not found',
            ], 401);
        }

        $contactItem = $this->getContactItem($user);

        return response()->json([
            'contact_item' => $contactItem,
        ], 200);
    }

    public function makeSeen(Request $request)
    {
        Message::where("from_id", $request->id)
            ->where('to_id', Auth::user()->id)
            ->where('seen', 0)->update(['seen' => 1]);

        return true;
    }

    // add/remove to favorite list
    public function favorite(Request $request)
    {
        $query = Favorite::where(["user_id" => Auth::user()->id, "favorite_id" => $request->id]);
        $favoriteStatus = $query->exists();

        if (!$favoriteStatus) {
            $star = new Favorite();
            $star->user_id = Auth::user()->id;
            $star->favorite_id = $request->id;
            $star->save();

            return response()->json([
                'status' => 'added'
            ]);
        } else {
            $query->delete();
            return response()->json([
                'status' => 'removed'
            ]);
        }
    }

    // delete message
    public function deleteMessage(Request $request)
    {
        $message = Message::findOrFail($request->message_id);

        if ($message->from_id === Auth::user()->id) {
            $message->delete();
            return response()->json([
                'id' => $request->message_id,
            ], 200);
        }

        return;
    }

}
