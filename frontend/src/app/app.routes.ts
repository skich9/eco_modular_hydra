import { Routes } from '@angular/router';
import { ApiTestComponent } from './components/api-test/api-test.component';

export const routes: Routes = [
	{ path: 'api-test', component: ApiTestComponent },
	{ path: '', redirectTo: '/api-test', pathMatch: 'full' }
];
