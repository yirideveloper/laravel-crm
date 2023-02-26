<?php

namespace Webkul\Admin\Http\Controllers\User;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\Http\Controllers\Controller;

class AccountController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function edit()
    {
        $user = auth()->guard('admin')->user();

        return view('admin::admin.account.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update()
    {
        $isPasswordChanged = false;
        $user = auth()->guard('admin')->user();

        $this->validate(request(), [
            'name'             => 'required',
            'email'            => 'email|unique:admins,email,' . $user->id,
            'password'         => 'nullable|min:6|confirmed',
            'current_password' => 'required|min:6',
        ]);

        $data = request()->input();

        if (! Hash::check($data['current_password'], auth()->guard('admin')->user()->password)) {
            session()->flash('warning', trans('admin::app.users.users.password-match'));

            return redirect()->back();
        }

        if (! $data['password']) {
            unset($data['password']);
        } else {
            $isPasswordChanged = true;
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);

        if ($isPasswordChanged) {
            Event::dispatch('user.admin.update-password', $user);
        }

        session()->flash('success', trans('admin::app.users.users.account-save'));

        return back();
    }
}