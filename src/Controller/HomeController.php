<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use Symfony\Component\VarDumper\VarDumper;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UserRepository;
use App\Service\ExcelReaderService;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HomeController extends AbstractController
{

    private ExcelReaderService $excelReaderService;

    public function __construct(ExcelReaderService $excelReaderService)
    {
        $this->excelReaderService = $excelReaderService;
    }
    #[Route('/', name: 'dashboard')]
    public function dashboard(){
        return $this->render('dashboard/dashboard.html.twig', [
            'controller_name'   => 'HomeController',
        ]);
    }
    #[Route('/home', name: 'app_home')]
    public function index(UserRepository $userRepository): Response
    {
        $getAllUsers = $userRepository->findAllUsersOrderByDesc();
        return $this->render('home/index.html.twig', [
            'controller_name'   => 'HomeController',
            'users'             => $getAllUsers,
        ]);
    }
    #[Route('/add', name: 'add_page')]
    public function add(): Response
    {
        return $this->render('home/add.html.twig', [
            'controller_name' => 'HomeController',
        ]);
    }
    #[Route('user/add', name: 'add_user', methods: ['POST'])]
    public function addUser(Request $request, EntityManagerInterface $entityManager)
    {
        $entityManager->beginTransaction();
        try {
            if ($request->isMethod('POST')) {
                $name = $request->request->get('name');
                $email = $request->request->get('email');

                $user = new User();
                $user->setName($name);
                $user->setEmail($email); // Corrected: use $email, not $name.
                // Persist the user entity
                $entityManager->persist($user);
                $entityManager->flush(); // This will save the entity to the database
                $entityManager->commit();
                $this->addFlash('success', 'User successfully created!');
                return $this->redirectToRoute('app_home'); // Redirect after successful form submission
            }
        } catch (\Throwable $th) {
            $entityManager->rollback();
            $this->addFlash('error', 'An error occurred while creating the user.');
            return $this->redirectToRoute('app_home'); // Redirect to an error route or another page
        }
    }
    #[Route('user/delete/{id}', name: 'delete_user', methods: ['GET'])]
    public function deleteUser($id,UserRepository $userRepository, EntityManagerInterface $entityManager){
        $user = $userRepository->find($id);
        // Check if the user exists
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_home');
        }
        // Remove the user from the database
        $entityManager->remove($user);
        $entityManager->flush();
        $this->addFlash('success', 'User deleted successfully.');
        return $this->redirectToRoute('app_home');
    }
    #[Route('user/preview/{id}', name: 'preview_user', methods: ['GET'])]
    public function previewUser($id,UserRepository $userRepository){
        $user = $userRepository->find($id);
        return $this->render('home/preview.html.twig', [
            'controller_name'   => 'HomeController',
            'user'              => $user,
        ]);
    }
    #[Route('user/edit/{id}', name: 'edit_user', methods: ['GET'])]
    public function editUser($id,UserRepository $userRepository){
        $user = $userRepository->find($id);
        return $this->render('home/edit.html.twig', [
            'controller_name'   => 'HomeController',
            'user'              => $user,
        ]);
    }
    #[Route('user/edit/{id}', name: 'edit_existing_user', methods: ['POST'])]
    public function editExistingUser($id, Request $request, EntityManagerInterface $entityManager)
    {
        // Start a transaction
        $entityManager->beginTransaction();
        try {
            // Fetch the user entity
            $user = $entityManager->getRepository(User::class)->find($id);
            if (!$user) {
                $this->addFlash('error', 'User not found!');
                return $this->redirectToRoute('app_home');
            }
            // Get updated values from the form
            $name = $request->request->get('name');
            $email = $request->request->get('email');

            // Simple validation (you can expand this as needed)
            if (empty($name) || empty($email)) {
                $this->addFlash('error', 'Name and email cannot be empty.');
                return $this->redirectToRoute('app_home', ['id' => $id]); // Redirect back to edit form
            }

            // Set the new values to the user entity
            $user->setName($name);
            $user->setEmail($email);

            // Persist and flush the entity to update it in the database
            $entityManager->persist($user);
            $entityManager->flush();
            // Commit the transaction
            $entityManager->commit();

            // Add success flash message
            $this->addFlash('success', 'User successfully updated!');
            return $this->redirectToRoute('app_home'); // Redirect to home or another page

        } catch (\Throwable $th) {
            // Rollback if an error occurs
            $entityManager->rollback();
            $this->addFlash('error', 'An error occurred while updating the user.');
            return $this->redirectToRoute('app_home'); // Redirect to an error route or another page
        }
    }


    #[Route('bulk_user', name: 'bulk_user_upload', methods: ['GET'])]
    public function bulk_user_upload() {
        return $this->render('home/bulk_user_upload.html.twig', [
            'controller_name'   => 'HomeController',
        ]);
    }



    #[Route('bulk_user_action', name: 'bulk_user_upload_submit', methods: ['POST'])]
    public function bulkUserUploadSubmit(Request $request): Response
    {
        // Get the uploaded file from the form
        $file = $request->files->get('file');

        if ($file && $file->isValid()) {
            try {
                // Load the XLSX file
                $spreadsheet = IOFactory::load($file->getPathname());
                // Get the first sheet
                $sheet = $spreadsheet->getActiveSheet();
                $data = [];
                // Iterate through each row in the sheet
                foreach ($sheet->getRowIterator() as $row) {
                    $rowData = [];
                    // Get the iterator for the current row's cells
                    $cellIterator = $row->getCellIterator();
                    // Iterate through each cell in the row (for all columns)
                    $cellIterator->setIterateOnlyExistingCells(false); // Ensure all columns are iterated over, even if they are empty
                    $columnIndex = 0; // To track column index
                    $skipRow = false; // Flag to decide if the row should be skipped
                    // Iterate over the cells and process data
                    foreach ($cellIterator as $cell) {
                        // Check for column 2 (index 1)
                        if ($columnIndex == 1) {
                            // If the value in column 2 is empty, skip this row
                            if (empty($cell->getValue())) {
                                $skipRow = true;
                                break;
                            }
                        }
                        // Add cell value to rowData
                        $rowData[] = $cell->getValue();
                        $columnIndex++;
                    }
                    // Skip row if column 2 was empty
                    if ($skipRow) {
                        continue; // Skip the row and move to the next one
                    }
                    // Add the row data to the main data array
                    $data[] = $rowData;
                }

                // Output the processed data for debugging
                dd($data);

                return new Response('File processed successfully!');
            } catch (\Exception $e) {
                return new Response('Error processing file: ' . $e->getMessage(), 500);
            }
        }
        // If no file or an invalid file is uploaded
        return new Response('No valid file uploaded.', 400);
    }






}
