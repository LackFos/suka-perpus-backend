<?php

namespace App\Http\Controllers;

use App\Enums\BorrowStatus;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Borrow\CreateBorrowRequest;
use App\Http\Requests\Borrow\UpdateBorrowRequest;
use App\Models\Book;
use App\Models\Borrow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BorrowController extends Controller
{
    public function all(Request $request)
    {
        try {
            $query = Borrow::latest();

            $userId = $request->query('user_id');

            if($userId) {
                $query->where('user_id', $userId);
            }

            $statusId = $request->query('status_id');

            if($statusId) {
                $query->where('borrow_status_id', $statusId);
            }
            
            $startDate = $request->query('startDate');
            $endDate = $request->query('endDate');

            if ($startDate && $endDate) {
                $dateRange = [$startDate, $endDate];
                $query->whereBetween('created_at', $dateRange);
            } else if ($startDate) {
                $query->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($startDate)->endOfDay()]);
            }

            $borrows = $query->with([
                'user',
                'books',
                'borrowStatus'
            ])->get();

            $borrows->each(function ($borrow) {
                $borrow->penalty_fee = $borrow->getPenaltyFee();
            });

            $message = $borrows->isNotEmpty() ? 'Borrow found' : 'Borrow not found';
            
            return ResponseHelper::returnOkResponse($message, $borrows);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }

    public function myBorrows() {
        try {
            /** @var User $user */
            $user = Auth::user();

            $borrows = $user->borrows()->with([
                'books'
            ])->get();
            
            $borrows->each(function ($borrow) {
                $borrow->penalty_fee = $borrow->getPenaltyFee();
            });

            return ResponseHelper::returnOkResponse('Borrow found', $borrows);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }

    public function detail(Borrow $borrow)
    {
        try {
            $penaltyFee = $borrow->getPenaltyFee();

            if ($penaltyFee) {
                $borrow['penalty_fee'] = $penaltyFee;
            }

            $borrow->load('books', 'borrowStatus');

            return ResponseHelper::returnOkResponse('Borrow found', $borrow);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }

    public function create(CreateBorrowRequest $request)
    {
        try {
            $validated = $request->validated();

            $validated['borrow_status_id'] = BorrowStatus::BORROWING;

            $user = User::findOrFail($validated['user_id']);
            $books = Book::findOrFail($validated['book_id']);

            foreach ($books as $book) {
                $currentBorrowedCount = $book->borrowed()->where('borrow_status_id', BorrowStatus::BORROWING)->count();

                $isOutOfStock = $currentBorrowedCount >= $book->stock;
                
                if ($isOutOfStock) {
                    return ResponseHelper::throwConflictError("The book with ID '" . $book->id . "' is currently unavailable.");
                }
            }

            $borrow = $user->borrows()->create($validated);
            $borrow->books()->sync($validated['book_id']);

            return ResponseHelper::returnOkResponse('Book borrowed successfully', $borrow);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }

    public function update(UpdateBorrowRequest $request, Borrow $borrow)
    {
        try {
            $validated = $request->validated();

            $borrow->update($validated);

            return ResponseHelper::returnOkResponse('Borrow updated successfully', $borrow);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }

    public function returnBook(Borrow $borrow)
    {
        try {
            $penaltyFee = $borrow->getPenaltyFee();

            if ($penaltyFee) {
                $borrow->penalty()->create(['amount' => $penaltyFee]);
            }

            $borrow->borrow_status_id = BorrowStatus::RETURNED;
            $borrow->save();

            return ResponseHelper::returnOkResponse('Borrow returned successfully', $borrow);
        } catch (\Exception $exception) {
            return ResponseHelper::throwInternalError($exception->getMessage());
        }
    }
}
